<?php

namespace App\Graph;

use App\Models\FileAsset;

class DatabaseAssetResolver implements AssetResolver
{
    public function isReadyForOrganization(string $assetId, int $organizationId): bool
    {
        return preg_match('/^[1-9][0-9]{0,17}$/D', $assetId) && FileAsset::query()->whereKey($assetId)
            ->where('organization_id', $organizationId)->where('state', 'ready')->exists();
    }
}
