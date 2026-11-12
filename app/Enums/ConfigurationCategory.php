<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The configuration surface, named after what an operator is trying to do.
 *
 * A category is the unit the settings API reads and writes, and the unit the
 * dashboard renders as one page. `Infrastructure` is special: it is read-only
 * by construction and explains which values must stay in the deployment
 * environment.
 */
enum ConfigurationCategory: string
{
    case General = 'general';
    case Localization = 'localization';
    case Email = 'email';
    case Storage = 'storage';
    case Security = 'security';
    case Features = 'features';
    case Whatsapp = 'whatsapp';
    case Maintenance = 'maintenance';
    case Ai = 'ai';
    case Infrastructure = 'infrastructure';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Localization => 'Localization',
            self::Email => 'Email & notifications',
            self::Storage => 'Storage & media',
            self::Security => 'Security',
            self::Features => 'Feature flags',
            self::Whatsapp => 'WhatsApp',
            self::Maintenance => 'Maintenance',
            self::Ai => 'AI runtime',
            self::Infrastructure => 'Infrastructure',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::General => 'عام',
            self::Localization => 'اللغة والمنطقة',
            self::Email => 'البريد والإشعارات',
            self::Storage => 'التخزين والوسائط',
            self::Security => 'الأمان',
            self::Features => 'مزايا المنصة',
            self::Whatsapp => 'واتساب',
            self::Maintenance => 'وضع الصيانة',
            self::Ai => 'إعدادات الذكاء الاصطناعي',
            self::Infrastructure => 'البنية التحتية',
        };
    }

    /**
     * Whether the category contains credentials and therefore needs the
     * dedicated secret permission.
     */
    public function holdsSecrets(): bool
    {
        return in_array($this, [self::Email, self::Storage, self::Whatsapp], true);
    }
}
