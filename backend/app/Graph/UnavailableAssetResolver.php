<?php

namespace App\Graph;

/** Assets are unavailable until the asset storage milestone supplies a resolver. */
final class UnavailableAssetResolver implements AssetResolver
{
    public function isReadyForOrganization(string $assetId, int $organizationId): bool
    {
        return false;
    }
}
