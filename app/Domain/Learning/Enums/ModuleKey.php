<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * The installable capability packs. An academy enables the ones it teaches, and
 * everything else — bot menu items, practice pickers, exam sections, reports —
 * follows from that single switch.
 *
 * @see docs/05-modules-exams-practice.md
 */
enum ModuleKey: string
{
    case PteSpeaking = 'pte_speaking';
    case PteListening = 'pte_listening';
    case PteReading = 'pte_reading';
    case PteWriting = 'pte_writing';
    case Vocabulary = 'vocabulary';
    case Grammar = 'grammar';
    case MockExam = 'mock_exam';
    case PlacementTest = 'placement_test';
    case Ielts = 'ielts';
    case Toefl = 'toefl';
    case GeneralEnglish = 'general_english';

    public function label(): string
    {
        return __('modules.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::PteSpeaking, self::Ielts => '🎙',
            self::PteListening => '🎧',
            self::PteReading => '📖',
            self::PteWriting => '✍',
            self::Vocabulary => '📚',
            self::Grammar => '📐',
            self::MockExam => '📝',
            self::PlacementTest => '🎯',
            self::Toefl => '🏛',
            self::GeneralEnglish => '💬',
        };
    }

    /**
     * Question types this module owns.
     *
     * @return array<int, QuestionType>
     */
    public function questionTypes(): array
    {
        return array_values(array_filter(
            QuestionType::cases(),
            fn (QuestionType $type): bool => $type->module() === $this
        ));
    }

    /** Modules shipped in phase 1–2; the rest are roadmap. */
    public function isAvailable(): bool
    {
        return in_array($this, [
            self::PteSpeaking,
            self::PteListening,
            self::PteReading,
            self::PteWriting,
            self::Vocabulary,
            self::MockExam,
            self::PlacementTest,
        ], true);
    }
}
