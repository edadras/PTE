<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * The actions docs/02 §7 makes mandatory, plus the ones the Ops layer records
 * itself. Kept as an enum so a rename cannot silently orphan a year of history:
 * the string in the column is the case value, and nothing else may write it.
 *
 * @see docs/02-roles-and-rbac.md §7
 */
enum AuditAction: string
{
    // Telegram
    case BotConnected = 'bot.connected';
    case BotTokenRotated = 'bot.token_rotated';
    case BotWebhookReset = 'bot.webhook_reset';

    // AI
    case PromptPublished = 'ai.prompt_published';
    case RubricPublished = 'ai.rubric_published';
    case AnswerRescored = 'ai.answer_rescored';

    // Assessment
    case ScoreOverridden = 'assessment.score_overridden';
    case ExamPublished = 'assessment.exam_published';
    case ExamDeleted = 'assessment.exam_deleted';

    // Identity
    case StaffInvited = 'identity.staff_invited';
    case StaffRemoved = 'identity.staff_removed';
    case RoleChanged = 'identity.role_changed';
    case StudentDeleted = 'identity.student_deleted';

    // Commerce
    case PlanChanged = 'commerce.plan_changed';
    case SubscriptionStatusChanged = 'commerce.subscription_status_changed';
    case PaymentRecorded = 'commerce.payment_recorded';

    // Reporting / data egress
    case DataExported = 'reporting.data_exported';
    case ReportGenerated = 'reporting.report_generated';

    // Support
    case TicketOpened = 'support.ticket_opened';
    case TicketReplied = 'support.ticket_replied';
    case TicketClosed = 'support.ticket_closed';
    case TicketAssigned = 'support.ticket_assigned';

    // Platform (super admin)
    case AcademyCreated = 'platform.academy_created';
    case AcademySuspended = 'platform.academy_suspended';
    case AcademyResumed = 'platform.academy_resumed';
    case AcademyDeleted = 'platform.academy_deleted';
    case AcademyCloned = 'platform.academy_cloned';
    case AcademyExported = 'platform.academy_exported';
    case Impersonated = 'platform.impersonated';
    case TenantCommandRun = 'platform.tenant_command_run';
    case RetentionSweep = 'platform.retention_sweep';

    // Generic model lifecycle, written by the LogsActivity trait.
    case ModelCreated = 'model.created';
    case ModelUpdated = 'model.updated';
    case ModelDeleted = 'model.deleted';

    public function label(): string
    {
        return __("support.audit.{$this->value}");
    }
}
