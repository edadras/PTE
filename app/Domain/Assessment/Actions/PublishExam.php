<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\ExamStatus;
use App\Domain\Assessment\Enums\SelectionMode;
use App\Domain\Assessment\Events\ExamPublished;
use App\Domain\Assessment\Exceptions\ExamNotAvailable;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSection;

/**
 * Publishes an exam after checking it can actually be sat.
 *
 * The validation is here rather than in a form request because an exam can be
 * published from the panel, the API or a scheduled job, and a paper with an
 * empty section only reveals itself when a student is already sitting it.
 */
final class PublishExam
{
    public function handle(Exam $exam): Exam
    {
        $sections = $exam->sections()->with('questions')->get();

        if ($sections->isEmpty()) {
            throw ExamNotAvailable::empty((int) $exam->getKey());
        }

        foreach ($sections as $section) {
            /** @var ExamSection $section */
            if ($this->plannedQuestionCount($section) < 1) {
                throw ExamNotAvailable::empty((int) $exam->getKey());
            }
        }

        $exam->forceFill([
            'status' => ExamStatus::Published,
            'published_at' => $exam->published_at ?? now(),
        ])->save();

        ExamPublished::dispatch($exam);

        return $exam;
    }

    private function plannedQuestionCount(ExamSection $section): int
    {
        return match ($section->selection_mode) {
            SelectionMode::Manual => $section->questions->count(),
            SelectionMode::Random => max(0, (int) $section->config('count', 0)),
            // A pool must hold at least as many candidates as it hands out.
            SelectionMode::Pool => min(
                $section->questions->count(),
                max(0, (int) $section->config('take', 0))
            ),
        };
    }
}
