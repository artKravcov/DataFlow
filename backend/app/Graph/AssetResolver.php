<?php

namespace App\Graph;

interface AssetResolver
{
    public function isReadyForOrganization(string $assetId, int $organizationId): bool;
}
