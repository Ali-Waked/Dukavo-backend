<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The stable identifiers for every kind of AI job the platform routes.
 *
 * A task is what the routing table is keyed on: the Platform Owner picks a
 * primary provider and fallback providers per task, not per deployment. The
 * existing feature keys map onto these tasks so historical usage rows and new
 * rows agree on what was asked for.
 *
 * New tasks must be appended — never inserted or renamed — because the value
 * is stored on usage rows and routing tables.
 */
enum AiTask: string
{
    case Translation = 'translation';
    case ProductDescription = 'product_description';
    case ProductContent = 'product_content';
    case CategoryContent = 'category_content';
    case MarketingContent = 'marketing_content';
    case SeoGeneration = 'seo_generation';
    case ConversationAnalysis = 'conversation_analysis';
    case ConversationSummary = 'conversation_summary';
    case WhatsAppAssistant = 'whatsapp_assistant';
    case CustomerReplySuggestion = 'customer_reply_suggestion';
    case SalesAnalysis = 'sales_analysis';
    case InventoryInsights = 'inventory_insights';
    case GeneralTextGeneration = 'general_text_generation';

    /**
     * The historical feature key that served this task before tasks existed.
     *
     * Routing and limits fall back to this feature's configuration when no
     * database row has been created for the task yet, so an unseeded
     * installation keeps behaving exactly as it did.
     */
    public function legacyFeatureKey(): ?string
    {
        return match ($this) {
            self::Translation => 'translation',
            self::ConversationSummary => 'summary',
            self::CustomerReplySuggestion => 'reply',
            self::MarketingContent => 'content',
            self::SalesAnalysis => 'sales_copilot',
            self::ProductContent => 'bundle_explanation',
            self::ConversationAnalysis => 'customer_summary',
            self::WhatsAppAssistant => 'recovery',
            self::InventoryInsights => 'insights',
            self::GeneralTextGeneration => 'merchant_copilot',
            self::ProductDescription,
            self::CategoryContent,
            self::SeoGeneration => null,
        };
    }

    /**
     * The task a legacy feature key belongs to.
     *
     * Features that predate tasks are mapped here so every recorded call
     * carries a task, whatever its age. Unmapped keys default to general
     * generation rather than a hole in the reporting.
     */
    public static function forFeatureKey(string $feature): self
    {
        return match ($feature) {
            'translation' => self::Translation,
            'summary' => self::ConversationSummary,
            'reply', 'assistant' => self::CustomerReplySuggestion,
            'content' => self::MarketingContent,
            'sales_copilot' => self::SalesAnalysis,
            'bundle_explanation' => self::ProductContent,
            'customer_summary' => self::ConversationAnalysis,
            'recovery' => self::WhatsAppAssistant,
            'insights' => self::InventoryInsights,
            'merchant_copilot' => self::GeneralTextGeneration,
            'voice_order' => self::ConversationAnalysis,
            'voice_transcription' => self::ConversationAnalysis,
            'voice_reply' => self::ConversationAnalysis,
            default => self::GeneralTextGeneration,
        };
    }
}
