<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\InputException;
use Phpanta\Exception\TooLargeException;
use Phpanta\Exception\UploadException;
use Phpanta\Support\BareArray;
use Phpanta\Support\BareString;
use Phpanta\Support\Charset;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;

/**
 * The MultipartParameters class. What a `multipart/form-data` body sent — its fields and its files —
 * as PHP parsed it, and the one reader of the two maps PHP parsed it into.
 *
 * **PHP's parse, not ours.** A multipart body never reaches `php://input`: PHP reads it before any
 * script runs, puts the fields in `$_POST` and each file in a temporary file named in `$_FILES`. So
 * this holds those two maps, the way {@link ServerParameters} holds `$_SERVER` — {@link self::fromGlobals()}
 * for the request the process was started for, `new MultipartParameters([...], [...])` for any other
 * — and it is the only place the framework names either superglobal.
 *
 * **What PHP's parse loses, and what it does not.** A name sent as a list — `a[]`, or one file
 * input that took several — arrives as an array, and is refused as sent more than once, as
 * {@link Input} refuses a url-encoded name sent twice — or, for files, as more than one file. A
 * plain name sent twice is the one case PHP
 * keeps the last of in silence, and nothing after it can tell. And PHP renames a name with a dot or
 * a space in it, which is why {@link \Phpanta\Form\Form} refuses a field named that way.
 */
#[BareString(
    'string',
    'the declared type of the collections of names and values this reads out, which are strings — '
    . 'the same scalar-in-a-class-string coincidence Input excuses.',
)]
final readonly class MultipartParameters
{
    /** @var array<array-key, mixed> */
    #[BareArray('the door: $_POST as PHP hands it over, typed by nothing, which this class is the one reader of')]
    private array $fields;

    /** @var array<array-key, mixed> */
    #[BareArray('the door: $_FILES as PHP hands it over, typed by nothing, which this class is the one reader of')]
    private array $files;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param array<array-key, mixed> $fields The fields, keyed as `$_POST` keys them.
     * @param array<array-key, mixed> $files  The files, keyed and shaped as `$_FILES` holds them.
     */
    #[BareArray('the door: the same two maps as self::$fields and self::$files, on their way in')]
    public function __construct(array $fields, array $files)
    {
        $this->fields = $fields;
        $this->files  = $files;
    }

    /**
     * What the body this process was started for sent, as PHP parsed it.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        return new self($_POST, $_FILES);
    }

    /**
     * Every field sent once, as a string, keyed by its name.
     *
     * @return SearchableCollection<string>
     */
    public function fields(): SearchableCollection
    {
        $fields = new SearchableCollection('string');

        foreach ($this->fields as $name => $value) {
            if (is_string($value)) {
                $fields = $fields->with((string) $name, $value);
            }
        }

        return $fields;
    }

    /**
     * The names sent as a list, which PHP hands over as an array rather than a value.
     *
     * @return Collection<string>
     */
    public function lists(): Collection
    {
        $lists = new Collection('string');

        foreach ($this->fields as $name => $value) {
            if (!is_string($value)) {
                $lists = $lists->with((string) $name);
            }
        }

        return $lists;
    }

    /**
     * The file sent as $parameter, or null where none was — no such name, or a file input left empty.
     *
     * @param Parameter $parameter
     * @return Upload|null
     * @throws TooLargeException if the file was larger than the host takes.
     * @throws InputException    if it arrived only in part, as a list, or with a name that is not UTF-8.
     * @throws UploadException   if the host could not keep it: no temporary directory, a failed write,
     *                           or an extension that stopped it.
     */
    public function upload(Parameter $parameter): ?Upload
    {
        $name  = (string) $parameter->value;
        $entry = $this->files[$name] ?? null;

        if ($entry === null) {
            return null;
        }

        // One file input that took several — or a name sent as `a[]` — arrives with every key an
        // array, the error code included.
        $error = is_array($entry) ? ($entry[FileEntryKey::Error->value] ?? null) : null;

        if (!is_int($error)) {
            throw new InputException(sprintf("'%s' sent more than one file.", $name));
        }

        return match ($error) {
            UPLOAD_ERR_OK         => self::uploaded($name, $entry),
            UPLOAD_ERR_NO_FILE    => null,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => throw new TooLargeException(
                sprintf("'%s' is larger than this host takes.", $name),
            ),
            UPLOAD_ERR_PARTIAL    => throw new InputException(sprintf("'%s' arrived only in part.", $name)),
            default               => throw new UploadException(sprintf(
                "'%s' could not be kept by PHP: upload error %d.",
                $name,
                $error,
            )),
        };
    }

    /**
     * The file one `$_FILES` entry describes, which PHP kept without a fault.
     *
     * @param string                  $name
     * @param array<array-key, mixed> $entry
     * @return Upload
     * @throws InputException if the name the browser gave it is not UTF-8.
     */
    #[BareArray('one $_FILES entry, on its way from the door into the Upload that types it')]
    private static function uploaded(string $name, array $entry): Upload
    {
        $clientName = $entry[FileEntryKey::Name->value] ?? null;
        $clientName = is_string($clientName) ? $clientName : '';

        if (!mb_check_encoding($clientName, Charset::Utf8->canonical())) {
            throw new InputException(sprintf("The name '%s' was sent with does not decode to UTF-8.", $name));
        }

        $file = $entry[FileEntryKey::TmpName->value] ?? null;
        $size = $entry[FileEntryKey::Size->value] ?? null;

        return new Upload($clientName, new File(is_string($file) ? $file : ''), is_int($size) ? $size : 0);
    }
}
