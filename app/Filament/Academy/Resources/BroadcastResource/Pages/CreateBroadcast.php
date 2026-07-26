<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\BroadcastResource\Pages;

use App\Domain\Telegram\Actions\StartBroadcast;
use App\Filament\Academy\Resources\BroadcastResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateBroadcast extends CreateRecord
{
    protected static string $resource = BroadcastResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(StartBroadcast::class)->create(
            title: (string) $data['title'],
            content: is_array($data['content'] ?? null) ? $data['content'] : [],
            audienceFilter: is_array($data['audience_filter'] ?? null) ? $data['audience_filter'] : [],
            scheduledAt: $data['scheduled_at'] ?? null,
            userId: auth()->id(),
        );
    }
}
