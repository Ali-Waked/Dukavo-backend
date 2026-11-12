<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\StoreIntegrationRequest;
use App\Http\Requests\Integrations\UpdateIntegrationRequest;
use App\Http\Resources\ChannelConnectionResource;
use App\Models\ChannelConnection;
use App\Services\Channels\ChannelConnectionManager;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant integration centre.
 *
 * Lists every channel Dukavo knows, the workspace's connections and their
 * health, and lets an authorised member connect, test, repair or disconnect a
 * channel. Credential values are write-only: the API returns the field names
 * and whether each is configured, never the secret.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly ChannelConnectionManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $channels = $this->manager->catalogue();

        foreach ($channels as &$channel) {
            $channel['connections'] = array_map(
                fn (ChannelConnection $connection): array => (new ChannelConnectionResource($connection))->resolve($request),
                $channel['connections'],
            );
        }

        unset($channel);

        return ApiResponse::success('', ['channels' => $channels]);
    }

    public function show(ChannelConnection $connection): ChannelConnectionResource
    {
        return new ChannelConnectionResource($connection->load('store'));
    }

    public function store(StoreIntegrationRequest $request): JsonResponse
    {
        $connection = $this->manager->connect($request->validated());

        return ApiResponse::created(
            'Integration saved.',
            new ChannelConnectionResource($connection->load('store')),
        );
    }

    public function update(UpdateIntegrationRequest $request, ChannelConnection $connection): ChannelConnectionResource
    {
        return new ChannelConnectionResource(
            $this->manager->update($connection, $request->validated())->load('store'),
        );
    }

    public function destroy(ChannelConnection $connection): JsonResponse
    {
        $this->manager->disconnect($connection);

        return ApiResponse::success('Integration disconnected.');
    }

    public function test(ChannelConnection $connection): ChannelConnectionResource
    {
        return new ChannelConnectionResource($this->manager->test($connection)->load('store'));
    }

    public function reconnect(ChannelConnection $connection): ChannelConnectionResource
    {
        return new ChannelConnectionResource($this->manager->reconnect($connection)->load('store'));
    }
}
