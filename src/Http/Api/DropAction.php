<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Exception\ApiException;
use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Drop\DropConfig;
use Phpanta\Model\Drop\DropManifest;
use Phpanta\Service\Api\DropCreate;
use Phpanta\Service\Api\DropList;
use Phpanta\Service\Api\DropRevoke;
use Phpanta\Service\Drop\DropCipher;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;

/**
 * The DropAction enum. What the `drop` service can be asked to do: keep a text or a file behind a
 * link, list what is kept, and take one away.
 *
 * **Only making a drop is the admin's; opening one is the link's.** A drop is opened at `/drop`, by
 * whoever holds its link — see {@link \Phpanta\Controller\DropController} — so what is here is what
 * the key holder, or a browser an enrolled passkey let in, does with drops, and a drop's contents are
 * never among it: the store keeps no link, and without one nothing opens.
 *
 * **Offered only where `data/drop.json` switches it on**, like the `machine` service: with no file,
 * nothing is offered and the service is not there at all — {@link self::offered()}.
 */
enum DropAction: string implements ApiAction
{
    /** A text or a file, kept behind a link. */
    case Create = 'create';

    /** The drops kept, and when each goes. */
    case List = 'list';

    /** A drop, taken away before it expires. */
    case Revoke = 'revoke';

    /**
     * What a deployment whose file says $config offers — nothing where it has none.
     *
     * @param DropConfig|null $config
     * @return Collection<self>
     */
    public static function offered(?DropConfig $config): Collection
    {
        return new Collection(self::class)->with(...($config === null ? [] : self::cases()));
    }

    /**
     * Where this action is — at the drop $subject names, for one that takes one.
     *
     * @param string|null $subject
     * @return string
     */
    public function href(?string $subject = null): string
    {
        $service = ApiService::Drop->value;
        $version = ApiVersion::V1->value;

        return $subject === null
            ? AdminPath::Action->to($service, $version, $this->value)
            : AdminPath::Subject->to($service, $version, $this->value, $subject);
    }

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return $this === self::List ? HttpMethod::Get : HttpMethod::Post;
    }

    /**
     * @return Translatable
     */
    public function describe(): Translatable
    {
        return match ($this) {
            self::Create => AdminText::DropCreate,
            self::List   => AdminText::DropList,
            self::Revoke => AdminText::DropRevoke,
        };
    }

    /**
     * Making one takes its text or its file and how it is kept; taking one away, `apply` alone.
     *
     * @return Collection<ActionField>
     */
    public function fields(): Collection
    {
        return new Collection(ActionField::class)->with(...match ($this) {
            self::Create => [
                ActionField::Text,
                ActionField::File,
                ActionField::Filename,
                ActionField::Lifetime,
                ActionField::Once,
                ActionField::Password,
                ActionField::Apply,
            ],
            self::Revoke => [ActionField::Apply],
            self::List   => [],
        });
    }

    /**
     * Every action: a browser the admin let in may make a drop, list them, and take one away.
     *
     * @return bool
     */
    public function fromBrowser(): bool
    {
        return true;
    }

    /**
     * Taking one away names it after the action — `revoke/<id>` — so a listing links each.
     *
     * @return bool
     */
    public function takesPath(): bool
    {
        return $this === self::Revoke;
    }

    /**
     * @param VerifiedRequest $verified
     * @param string|null     $path     The drop, for `revoke`.
     * @return ApiHandler
     * @throws ApiException if the service is switched off since the action was resolved, the
     *                      deployment has no drop key, or a write's manifest does not read.
     */
    public function handler(VerifiedRequest $verified, ?string $path = null): ApiHandler
    {
        $config = DropConfig::current() ?? throw new ApiException(
            'the drop service is off here: data/drop.json is not there, or does not read',
        );
        $key    = App::current()->dataFile(CredentialFile::DropKey);
        $cipher = DropCipher::current($key) ?? throw new ApiException(sprintf(
            '%s holds no drop key: %s',
            $key->path,
            DropCipher::minting($key),
        ));
        $store  = new DropStore(App::current()->data()->directory(DropStore::DIRECTORY), $cipher);

        return match ($this) {
            self::Create => new DropCreate(
                $store,
                $config,
                DropManifest::parse($verified->manifest, $this),
                $verified->body,
                $verified->uploads,
            ),
            self::List   => new DropList($store),
            self::Revoke => new DropRevoke($store, $path, DropManifest::parse($verified->manifest, $this)),
        };
    }
}
