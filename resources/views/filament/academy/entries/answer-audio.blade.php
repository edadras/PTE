@php
    $record = $getRecord();
    $url = \App\Filament\Academy\Resources\AnswerResource::mediaUrl($record);
@endphp

@if ($url === null)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('panel.answers.audio_unavailable') }}</p>
@else
    <audio controls preload="none" class="w-full" src="{{ $url }}"></audio>
@endif
