<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Learning\Actions\CloneQuestionBank;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Tenancy\Actions\CloneAcademy;
use App\Domain\Tenancy\Data\CreateAcademyData;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stand up a new academy shaped like an existing one.
 *
 * The Tenancy action copies what Tenancy owns (brand, settings, modules, custom
 * copy). `--content` additionally asks the Learning context to copy the question
 * banks — that is a separate flag because a franchise clone usually wants the
 * branding but its own questions, and copying tens of thousands of rows by
 * accident is expensive and hard to undo.
 *
 * @see docs/10-infrastructure-and-ops.md §8 · docs/01-multi-tenancy.md §8
 */
final class AcademyCloneCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'academy:clone
        {from : Source academy id or slug}
        {to : Name of the new academy}
        {--slug= : Slug for the new academy}
        {--owner-email= : Owner of the new academy}
        {--content : Also copy the question banks}
        {--only-published : With --content, copy published questions only}';

    protected $description = 'Clone an academy: brand, settings, modules, message templates and optionally its content.';

    public function handle(CloneAcademy $clone, CloneQuestionBank $cloneBank, AuditRecorder $audit): int
    {
        $source = $this->requireAcademy($this->argument('from'), withTrashed: false);

        if ($source === null) {
            return self::FAILURE;
        }

        try {
            $target = $clone->handle($source, new CreateAcademyData(
                name: (string) $this->argument('to'),
                slug: $this->stringOption('slug'),
                ownerEmail: $this->stringOption('owner-email'),
                locale: (string) ($source->settings?->locale ?? 'fa'),
                currency: (string) ($source->settings?->currency ?? 'IRR'),
                timezone: (string) ($source->timezone ?? 'Asia/Tehran'),
                planId: $source->plan_id === null ? null : (int) $source->plan_id,
            ));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $banks = 0;

        if ($this->option('content') === true) {
            $banks = $this->copyContent($source, $target, $cloneBank);
        }

        $audit->recordPlatform(AuditAction::AcademyCloned, $target, [
            'source_academy_id' => (int) $source->getKey(),
            'source_slug' => $source->slug,
            'with_content' => $this->option('content') === true,
            'question_banks_copied' => $banks,
        ]);

        $this->components->info(__('reports.console.academy_cloned', [
            'source' => (string) $source->slug,
            'target' => (string) $target->slug,
            'banks' => (string) $banks,
        ]));

        return self::SUCCESS;
    }

    private function copyContent(Academy $source, Academy $target, CloneQuestionBank $cloneBank): int
    {
        $onlyPublished = $this->option('only-published') === true;

        $banks = TenantContext::runFor(
            $source,
            static fn () => QuestionBank::query()->orderBy('id')->get()
        );

        $copied = 0;

        foreach ($banks as $bank) {
            // The clone runs in the *source* tenant: CloneQuestionBank reads the
            // source rows and stamps the target id itself.
            TenantContext::runFor($source, function () use ($cloneBank, $bank, $target, $onlyPublished): void {
                $cloneBank->handle($bank, null, $target, $onlyPublished);
            });

            $copied++;
        }

        return $copied;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
