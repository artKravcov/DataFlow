<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    private function view(FileAsset $asset): array
    {
        return ['id' => $asset->id, 'name' => $asset->original_name, 'size_bytes' => $asset->size_bytes, 'created_at' => $asset->created_at];
    }

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);
        $assets = FileAsset::where('organization_id', $organization->id)->where('state', 'ready')->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $assets->getCollection()->map(fn ($asset) => $this->view($asset))->values(), 'meta' => ['current_page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()]]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);
        Gate::authorize('create', [Project::class, $organization]);
        $file = $request->file('file');
        if ($file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            abort(413, 'File is too large.');
        }
        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'A valid CSV file is required.']);
        }
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if (strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw ValidationException::withMessages(['file' => 'Choose a CSV file with a valid name.']);
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('assets');
        $id = (string) Str::uuid();
        $key = 'assets/'.$id;
        $source = fopen($file->getRealPath(), 'rb');
        if (! $source) {
            throw new \RuntimeException('Cannot read upload.');
        }
        try {
            $target = fopen($disk->path($key), 'xb');
        } catch (\Throwable $e) {
            fclose($source);
            throw $e;
        }
        if (! $target) {
            fclose($source);
            throw new \RuntimeException('Cannot store asset.');
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            while (! feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot read upload.');
                }
                $size += strlen($chunk);
                if ($size > config('dataflow.max_input_bytes')) {
                    abort(413, 'File is too large.');
                }
                if (str_contains($chunk, "\0")) {
                    throw ValidationException::withMessages(['file' => 'CSV must be text.']);
                }
                hash_update($hash, $chunk);
                if ($chunk !== '' && fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Cannot store asset.');
                }
            }
            if ($size === 0) {
                throw ValidationException::withMessages(['file' => 'CSV cannot be empty.']);
            }
            if (! fflush($target)) {
                throw new \RuntimeException('Cannot store asset.');
            }
            fclose($target);
            $target = null;
            fclose($source);
            $source = null;
            $asset = FileAsset::create(['organization_id' => $organization->id, 'original_name' => $name, 'storage_key' => $key, 'size_bytes' => $size, 'sha256' => hash_final($hash), 'state' => 'ready']);
        } catch (\Throwable $e) {
            if ($source) {
                fclose($source);
            }
            if ($target) {
                fclose($target);
            }
            $disk->delete($key);
            throw $e;
        }

        return response()->json(['data' => $this->view($asset)], 201);
    }

    public function download(Organization $organization, string $asset): StreamedResponse
    {
        Gate::authorize('view', $organization);
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/D', $asset), 404);
        $item = FileAsset::where('organization_id', $organization->id)->where('state', 'ready')->findOrFail($asset);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($item->storage_key), 404);

        return $disk->download($item->storage_key, $item->original_name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
