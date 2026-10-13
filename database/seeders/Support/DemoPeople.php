<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * The humans and businesses in the demo data: the platform operators, the four
 * merchants with their stores and staff, the customer name pool, delivery
 * addresses and the WhatsApp conversation scripts.
 *
 * Everything here is data rather than logic, and every value is invented:
 * phone numbers come from the NANP fiction block (+1 202 555-01XX, which is
 * reserved for use in works of fiction and can never reach a real person),
 * emails use reserved example domains, and no address belongs to a real
 * resident. Seeders read this file so the demo dataset can be reviewed without
 * reading code.
 */
final class DemoPeople
{
    /**
     * Phone numbers are built from the NANP reserved block 555-0100..0199.
     * Each population gets its own slice so no two roles collide.
     */
    private const PHONE_PREFIX = '+1202555';

    private const CUSTOMER_PHONE_OFFSET = 100;

    /**
     * The tenants the platform ships with. `tiers` decides how deep the
     * merchant's assortment is (see {@see DemoCatalog::productKeys()}) and
     * `omit` drops individual lines, which is what makes a fresh-grocery shop
     * look different from a general supermarket instead of like a smaller
     * copy of it.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     locale: string,
     *     contact_email: string,
     *     phone: string,
     *     tiers: int,
     *     omit: list<string>,
     *     order_count: int,
     *     customer_count: int,
     *     conversation_count: int,
     *     stores: list<array{name: string, slug: string, email: string, phone: string}>,
     *     members: list<array{name: string, email: string, phone: string, role: string, is_owner: bool}>,
     *     whatsapp: array{name: string, phone: string},
     * }>
     */
    public static function tenants(): array
    {
        return [
            [
                'name' => 'Urban Basket',
                'slug' => 'urban-basket',
                'locale' => 'en',
                'contact_email' => 'hello@urban-basket.test',
                'phone' => '+12025550158',
                'tiers' => 3,
                'omit' => [],
                'order_count' => 60,
                'customer_count' => 35,
                'conversation_count' => 9,
                'stores' => [
                    ['name' => 'City Centre', 'slug' => 'city-centre', 'email' => 'city-centre@urban-basket.test', 'phone' => '+12025550150'],
                    ['name' => 'Riverside', 'slug' => 'riverside', 'email' => 'riverside@urban-basket.test', 'phone' => '+12025550151'],
                ],
                'members' => [
                    ['name' => 'Claire Whitfield', 'email' => 'claire@urban-basket.test', 'phone' => '+12025550137', 'role' => 'merchant', 'is_owner' => true],
                    ['name' => 'Marcus Reed', 'email' => 'marcus@urban-basket.test', 'phone' => '+12025550138', 'role' => 'staff', 'is_owner' => false],
                    ['name' => 'Sofia Marino', 'email' => 'sofia@urban-basket.test', 'phone' => '+12025550139', 'role' => 'staff', 'is_owner' => false],
                ],
                'whatsapp' => ['name' => 'Urban Basket Support', 'phone' => '+12025550162'],
            ],
            [
                'name' => 'Nour Market',
                'slug' => 'nour-market',
                'locale' => 'ar',
                'contact_email' => 'hello@nour-market.test',
                'phone' => '+12025550159',
                'tiers' => 2,
                'omit' => [
                    'mango-nectar', 'instant-coffee', 'gummy-candy', 'garbage-bags', 'conditioner',
                    'body-wash', 'hand-soap', 'infant-formula', 'kidney-beans', 'tomato-paste',
                ],
                'order_count' => 42,
                'customer_count' => 32,
                'conversation_count' => 8,
                'stores' => [
                    ['name' => 'An-Nour Main', 'slug' => 'an-nour-main', 'email' => 'main@nour-market.test', 'phone' => '+12025550152'],
                ],
                'members' => [
                    ['name' => 'Omar Khalil', 'email' => 'omar@nour-market.test', 'phone' => '+12025550140', 'role' => 'merchant', 'is_owner' => true],
                    ['name' => 'Lina Saad', 'email' => 'lina@nour-market.test', 'phone' => '+12025550141', 'role' => 'staff', 'is_owner' => false],
                ],
                'whatsapp' => ['name' => 'Nour Market WhatsApp', 'phone' => '+12025550163'],
            ],
            [
                'name' => 'Gaza Fresh',
                'slug' => 'gaza-fresh',
                'locale' => 'ar',
                'contact_email' => 'hello@gaza-fresh.test',
                'phone' => '+12025550160',
                'tiers' => 2,
                'omit' => [
                    'cheddar-slices', 'fresh-butter', 'sourdough-loaf', 'butter-croissants', 'tortilla-chips',
                    'laundry-detergent', 'surface-cleaner', 'garbage-bags', 'conditioner', 'body-wash', 'hand-soap',
                ],
                'order_count' => 36,
                'customer_count' => 30,
                'conversation_count' => 7,
                'stores' => [
                    ['name' => 'Shejaea', 'slug' => 'shejaea', 'email' => 'shejaea@gaza-fresh.test', 'phone' => '+12025550153'],
                    ['name' => 'Tel Al-Hawa', 'slug' => 'tel-al-hawa', 'email' => 'tel-al-hawa@gaza-fresh.test', 'phone' => '+12025550154'],
                ],
                'members' => [
                    ['name' => 'Yousef Amin', 'email' => 'yousef@gaza-fresh.test', 'phone' => '+12025550142', 'role' => 'merchant', 'is_owner' => true],
                    ['name' => 'Rana Hijazi', 'email' => 'rana@gaza-fresh.test', 'phone' => '+12025550143', 'role' => 'staff', 'is_owner' => false],
                    ['name' => 'Tariq Nasser', 'email' => 'tariq@gaza-fresh.test', 'phone' => '+12025550144', 'role' => 'staff', 'is_owner' => false],
                ],
                'whatsapp' => ['name' => 'Gaza Fresh WhatsApp', 'phone' => '+12025550164'],
            ],
            [
                'name' => 'Daily Needs',
                'slug' => 'daily-needs',
                'locale' => 'en',
                'contact_email' => 'hello@daily-needs.test',
                'phone' => '+12025550161',
                'tiers' => 1,
                'omit' => [],
                'order_count' => 28,
                'customer_count' => 26,
                'conversation_count' => 7,
                'stores' => [
                    ['name' => 'Corner Express', 'slug' => 'corner-express', 'email' => 'corner@daily-needs.test', 'phone' => '+12025550155'],
                ],
                'members' => [
                    ['name' => 'Daniel Ortiz', 'email' => 'daniel@daily-needs.test', 'phone' => '+12025550145', 'role' => 'merchant', 'is_owner' => true],
                    ['name' => 'Grace Miller', 'email' => 'grace@daily-needs.test', 'phone' => '+12025550146', 'role' => 'staff', 'is_owner' => false],
                ],
                'whatsapp' => ['name' => 'Daily Needs WhatsApp', 'phone' => '+12025550165'],
            ],
        ];
    }

    /**
     * The identities that belong to Dukavo itself rather than to a merchant.
     *
     * `admin@gmail.com` is the login the demo is built around: a Super Admin
     * with no tenant of its own, which is why {@see Database\Seeders\TenantSeeder}
     * also adds it as a member of every workspace.
     *
     * @return list<array{name: string, email: string, phone: string, role: string}>
     */
    public static function platformUsers(): array
    {
        return [
            ['name' => 'Alex Morgan', 'email' => 'admin@gmail.com', 'phone' => '+12025550135', 'role' => 'super-admin'],
            ['name' => 'Priya Shah', 'email' => 'support@dukavo.test', 'phone' => '+12025550136', 'role' => 'dukavo-admin'],
        ];
    }

    /**
     * The customer for the given tenant-local index, in the tenant's language.
     *
     * Names are drawn from a fixed pool with a coprime stride, so a tenant's
     * customers look shuffled while staying identical from run to run.
     *
     * @return array{name: string, email: string, phone: string, locale: string}
     */
    public static function customer(string $locale, int $tenantOffset, int $index): array
    {
        $first = self::FIRST_NAMES[(($tenantOffset + $index * 13) % count(self::FIRST_NAMES))];

        // Both pools wrap every 24 names, so a plain stride would give the 25th
        // customer the same full name — and the same email — as the first.
        // Shifting the surname once per full cycle keeps every customer in a
        // 35-person tenant distinct.
        $cycle = intdiv($index, count(self::FIRST_NAMES));
        $last = self::LAST_NAMES[(($tenantOffset * 7 + $index * 29 + $cycle * 11) % count(self::LAST_NAMES))];

        return [
            'name' => $locale === 'ar' ? $first['ar'].' '.$last['ar'] : $first['en'].' '.$last['en'],
            'email' => $first['mail'].'.'.$last['mail'].'@example.com',
            'phone' => self::phone(self::CUSTOMER_PHONE_OFFSET, $index),
            'locale' => $locale,
        ];
    }

    /**
     * A delivery address in the tenant's language. Palestine uses no postal
     * codes in everyday addressing, so `postal_code` stays empty.
     *
     * @return array{contact_name: string, line_one: string, line_two: string|null, city: string, state: string, postal_code: string|null, country: string}
     */
    public static function address(string $locale, int $index, string $contactName): array
    {
        $pool = $locale === 'ar' ? self::ADDRESSES_AR : self::ADDRESSES_EN;
        $address = $pool[$index % count($pool)];

        return [
            'contact_name' => $contactName,
            'line_one' => $address['line_one'],
            'line_two' => $address['line_two'],
            'city' => $address['city'],
            'state' => $address['state'],
            'postal_code' => null,
            'country' => 'PS',
        ];
    }

    /**
     * Ready-to-play WhatsApp threads for a locale. `{order}` in a subject is
     * replaced with a real order number from the tenant being seeded.
     *
     * @return list<array{subject: string|null, messages: list<array{direction: string, body: string}>}>
     */
    public static function messageScripts(string $locale): array
    {
        if ($locale === 'ar') {
            return [
                [
                    'subject' => 'طلب {order}',
                    'messages' => [
                        ['direction' => 'in', 'body' => 'مرحبا، هل تم تأكيد طلبي؟'],
                        ['direction' => 'out', 'body' => 'أهلاً بك، نعم تم تأكيد الطلب وجاري تجهيزه الآن.'],
                        ['direction' => 'in', 'body' => 'متى يصل إليّ تقريباً؟'],
                        ['direction' => 'out', 'body' => 'اليوم قبل الخامسة مساءً إن شاء الله، ستصلك رسالة عند الخروج للتوصيل.'],
                        ['direction' => 'in', 'body' => 'شكراً لكم'],
                    ],
                ],
                [
                    'subject' => null,
                    'messages' => [
                        ['direction' => 'in', 'body' => 'هل يوجد سبانخ طازج اليوم؟'],
                        ['direction' => 'out', 'body' => 'نفدت الكمية اليوم، لكن تصل شحنة جديدة صباح الغد.'],
                        ['direction' => 'in', 'body' => 'هل يمكنكم حزم كمية لي غداً؟'],
                        ['direction' => 'out', 'body' => 'بالتأكيد، سجّلت الطلب عليك وننبهك عند وصولها.'],
                    ],
                ],
                [
                    'subject' => 'تغيير عنوان التوصيل',
                    'messages' => [
                        ['direction' => 'in', 'body' => 'أرغب بتغيير عنوان التوصيل إلى نابلس، شارع الينابيع.'],
                        ['direction' => 'out', 'body' => 'تم تحديث العنوان، هل تريد إضافة معلق تواصل؟'],
                        ['direction' => 'in', 'body' => 'نعم، الرقم نفسه يكفي. شكراً'],
                        ['direction' => 'out', 'body' => 'تمام، تم الحفظ. سيصلك الطلب على العنوان الجديد.'],
                    ],
                ],
                [
                    'subject' => 'شكوى',
                    'messages' => [
                        ['direction' => 'in', 'body' => 'وصلتني طماطم تالفة في الطلب الأخير.'],
                        ['direction' => 'out', 'body' => 'نعتذر جداً عن ذلك، تم تسجيل الصورة وإرجاع قيمة المنتج لحسابك.'],
                        ['direction' => 'in', 'body' => 'شكراً على التجاوب السريع.'],
                        ['direction' => 'out', 'body' => 'العفو، ومن حقك أي وقت التواصل معنا.'],
                    ],
                ],
                [
                    'subject' => null,
                    'messages' => [
                        ['direction' => 'in', 'body' => 'كم سعر طبق الأرز البسمتي عشر كيلو؟'],
                        ['direction' => 'out', 'body' => 'السعر 31.50 دولار للعبوة، وهو ضمن عروض هذا الأسبوع.'],
                        ['direction' => 'in', 'body' => 'ممتاز، ساطلب عبر الموقع.'],
                    ],
                ],
                [
                    'subject' => 'إيصال الدفع',
                    'messages' => [
                        ['direction' => 'in', 'body' => 'هل يمكن إرسال إيصال للطلب؟'],
                        ['direction' => 'out', 'body' => 'تم إرسال الإيصال إلى بريدك الإلكتروني، تفضل الاطلاع.'],
                        ['direction' => 'in', 'body' => 'وصل، شكراً لكم.'],
                    ],
                ],
                [
                    'subject' => null,
                    'messages' => [
                        ['direction' => 'in', 'body' => 'ما ساعات العمل يوم الجمعة؟'],
                        ['direction' => 'out', 'body' => 'نفتح من الثامنة صباحاً حتى العاشرة مساءً، وما عدا ذلك كالمعتاد.'],
                        ['direction' => 'in', 'body' => 'شكراً'],
                        ['direction' => 'out', 'body' => 'يسعدنا خدمتك في أي وقت.'],
                    ],
                ],
                [
                    'subject' => 'بديل المنتج',
                    'messages' => [
                        ['direction' => 'in', 'body' => 'نفدت الحليب كامل الدسم، هل هناك بديل؟'],
                        ['direction' => 'out', 'body' => 'يتوفر حليب منزوع الدسم و اللبن الطازج، أيهما تفضل؟'],
                        ['direction' => 'in', 'body' => 'اللبن الطازج من فضلكم.'],
                        ['direction' => 'out', 'body' => 'تم، أضفناه مكانه في الطلب.'],
                    ],
                ],
                [
                    'subject' => 'استرجاع',
                    'messages' => [
                        ['direction' => 'in', 'body' => 'طلبت أكثر من اللازم، هل يمكن إرجاع عبوتين؟'],
                        ['direction' => 'out', 'body' => 'نعم، يتم الاسترجاع خلال يومين من وصول المنتج.'],
                        ['direction' => 'in', 'body' => 'متى تصل المبلغ إلى حسابي؟'],
                        ['direction' => 'out', 'body' => 'خلال ثلاثة أيام عمل من استلام الإرجاع.'],
                        ['direction' => 'in', 'body' => 'مفهوم، شكراً لك.'],
                    ],
                ],
            ];
        }

        return [
            [
                'subject' => 'Order {order}',
                'messages' => [
                    ['direction' => 'in', 'body' => 'Hi, has my order been confirmed yet?'],
                    ['direction' => 'out', 'body' => 'Hello! Yes, your order is confirmed and the team is picking it now.'],
                    ['direction' => 'in', 'body' => 'Great. Any idea when it will arrive?'],
                    ['direction' => 'out', 'body' => 'It will be out for delivery before 5pm today. You will get a message when the driver leaves.'],
                    ['direction' => 'in', 'body' => 'Perfect, thank you!'],
                ],
            ],
            [
                'subject' => null,
                'messages' => [
                    ['direction' => 'in', 'body' => 'Do you have baby spinach in today?'],
                    ['direction' => 'out', 'body' => 'We sold out this morning, but a fresh crate arrives tomorrow at 8am.'],
                    ['direction' => 'in', 'body' => 'Could you put a bag aside for me?'],
                    ['direction' => 'out', 'body' => 'Done — I have added a note to hold one for you tomorrow.'],
                ],
            ],
            [
                'subject' => 'Change of delivery address',
                'messages' => [
                    ['direction' => 'in', 'body' => 'I need to change the delivery address for my order to 24 Nablus Road.'],
                    ['direction' => 'out', 'body' => 'Address updated. Would you like to add a buzzer name or a landmark?'],
                    ['direction' => 'in', 'body' => 'The same phone number is fine, thanks.'],
                    ['direction' => 'out', 'body' => 'All saved. Your order will go to the new address.'],
                ],
            ],
            [
                'subject' => 'Damaged item',
                'messages' => [
                    ['direction' => 'in', 'body' => 'Some of the tomatoes in my box were bruised.'],
                    ['direction' => 'out', 'body' => 'I am sorry about that. I have refunded that line to your card already.'],
                    ['direction' => 'in', 'body' => 'That was quick, appreciate it.'],
                    ['direction' => 'out', 'body' => 'Any time — just message us if anything else arrives less than perfect.'],
                ],
            ],
            [
                'subject' => null,
                'messages' => [
                    ['direction' => 'in', 'body' => 'What is the price of the 10kg basmati?'],
                    ['direction' => 'out', 'body' => 'It is $31.50 for the 10kg sack, and it is on this week\'s offer.'],
                    ['direction' => 'in', 'body' => 'Lovely, I will order it on the site.'],
                ],
            ],
            [
                'subject' => 'Receipt please',
                'messages' => [
                    ['direction' => 'in', 'body' => 'Could you send me a receipt for order {order}?'],
                    ['direction' => 'out', 'body' => 'Sent to your email just now — let me know if it did not arrive.'],
                    ['direction' => 'in', 'body' => 'Got it, thanks.'],
                ],
            ],
            [
                'subject' => null,
                'messages' => [
                    ['direction' => 'in', 'body' => 'What time do you open on Friday?'],
                    ['direction' => 'out', 'body' => 'We open at 8am and close at 10pm on Fridays.'],
                    ['direction' => 'in', 'body' => 'Thanks!'],
                    ['direction' => 'out', 'body' => 'Happy to help — see you then.'],
                ],
            ],
            [
                'subject' => 'Substitution',
                'messages' => [
                    ['direction' => 'in', 'body' => 'The full fat milk was out of stock — what can I swap it for?'],
                    ['direction' => 'out', 'body' => 'We have skimmed milk and fresh labneh in stock. Which would you prefer?'],
                    ['direction' => 'in', 'body' => 'The labneh please.'],
                    ['direction' => 'out', 'body' => 'Added to your order as a substitution at the same price.'],
                ],
            ],
            [
                'subject' => 'Refund',
                'messages' => [
                    ['direction' => 'in', 'body' => 'I ordered too much by mistake — can I return two packs?'],
                    ['direction' => 'out', 'body' => 'Of course. Returns are accepted within two days of delivery.'],
                    ['direction' => 'in', 'body' => 'When will the money come back?'],
                    ['direction' => 'out', 'body' => 'Back on your card within three working days of the return reaching us.'],
                    ['direction' => 'in', 'body' => 'Understood, thank you.'],
                ],
            ],
        ];
    }

    /**
     * A phone number from the reserved fiction block.
     */
    public static function phone(int $offset, int $index): string
    {
        return self::PHONE_PREFIX.sprintf('%04d', $offset + $index);
    }

    /**
     * @var list<array{en: string, ar: string, mail: string}>
     */
    private const FIRST_NAMES = [
        ['en' => 'Daniel', 'ar' => 'دانيال', 'mail' => 'daniel'],
        ['en' => 'Maya', 'ar' => 'مايا', 'mail' => 'maya'],
        ['en' => 'Jonas', 'ar' => 'يونس', 'mail' => 'jonas'],
        ['en' => 'Hana', 'ar' => 'هناء', 'mail' => 'hana'],
        ['en' => 'Ibrahim', 'ar' => 'إبراهيم', 'mail' => 'ibrahim'],
        ['en' => 'Sofia', 'ar' => 'صوفيا', 'mail' => 'sofia'],
        ['en' => 'Karim', 'ar' => 'كريم', 'mail' => 'karim'],
        ['en' => 'Elena', 'ar' => 'إيلينا', 'mail' => 'elena'],
        ['en' => 'Nadia', 'ar' => 'نادية', 'mail' => 'nadia'],
        ['en' => 'Thomas', 'ar' => 'توماس', 'mail' => 'thomas'],
        ['en' => 'Layla', 'ar' => 'ليلى', 'mail' => 'layla'],
        ['en' => 'Samir', 'ar' => 'سمير', 'mail' => 'samir'],
        ['en' => 'Grace', 'ar' => 'غريس', 'mail' => 'grace'],
        ['en' => 'Adel', 'ar' => 'عادل', 'mail' => 'adel'],
        ['en' => 'Ruth', 'ar' => 'روث', 'mail' => 'ruth'],
        ['en' => 'Farid', 'ar' => 'فريد', 'mail' => 'farid'],
        ['en' => 'Julia', 'ar' => 'جوليا', 'mail' => 'julia'],
        ['en' => 'Amir', 'ar' => 'أمير', 'mail' => 'amir'],
        ['en' => 'Clara', 'ar' => 'كلارا', 'mail' => 'clara'],
        ['en' => 'Hadi', 'ar' => 'هادي', 'mail' => 'hadi'],
        ['en' => 'Nora', 'ar' => 'نورا', 'mail' => 'nora'],
        ['en' => 'Rami', 'ar' => 'رامي', 'mail' => 'rami'],
        ['en' => 'Talia', 'ar' => 'تاليا', 'mail' => 'talia'],
        ['en' => 'Ziad', 'ar' => 'زياد', 'mail' => 'ziad'],
    ];

    /**
     * @var list<array{en: string, ar: string, mail: string}>
     */
    private const LAST_NAMES = [
        ['en' => 'Bennett', 'ar' => 'بينيت', 'mail' => 'bennett'],
        ['en' => 'Khalil', 'ar' => 'خليل', 'mail' => 'khalil'],
        ['en' => 'Ortiz', 'ar' => 'أورتيز', 'mail' => 'ortiz'],
        ['en' => 'Haddad', 'ar' => 'حداد', 'mail' => 'haddad'],
        ['en' => 'Mercer', 'ar' => 'مرسر', 'mail' => 'mercer'],
        ['en' => 'Saleh', 'ar' => 'صالح', 'mail' => 'saleh'],
        ['en' => 'Lindqvist', 'ar' => 'ليندكفيست', 'mail' => 'lindqvist'],
        ['en' => 'Nasser', 'ar' => 'ناصر', 'mail' => 'nasser'],
        ['en' => 'Foster', 'ar' => 'فوستر', 'mail' => 'foster'],
        ['en' => 'Awad', 'ar' => 'عوض', 'mail' => 'awad'],
        ['en' => 'Moreau', 'ar' => 'مورو', 'mail' => 'moreau'],
        ['en' => 'Zayed', 'ar' => 'زايد', 'mail' => 'zayed'],
        ['en' => 'Hughes', 'ar' => 'هيوز', 'mail' => 'hughes'],
        ['en' => 'Baraka', 'ar' => 'بركة', 'mail' => 'baraka'],
        ['en' => 'Novak', 'ar' => 'نوفاك', 'mail' => 'novak'],
        ['en' => 'Hamdan', 'ar' => 'حمدان', 'mail' => 'hamdan'],
        ['en' => 'Reyes', 'ar' => 'رييس', 'mail' => 'reyes'],
        ['en' => 'Safar', 'ar' => 'سفر', 'mail' => 'safar'],
        ['en' => 'Delaney', 'ar' => 'ديلايني', 'mail' => 'delaney'],
        ['en' => 'Musleh', 'ar' => 'مسلح', 'mail' => 'musleh'],
        ['en' => 'Vargas', 'ar' => 'فارغاس', 'mail' => 'vargas'],
        ['en' => 'Shammas', 'ar' => 'شماص', 'mail' => 'shammas'],
        ['en' => 'Whitfield', 'ar' => 'ويتفيلد', 'mail' => 'whitfield'],
        ['en' => 'Dabbagh', 'ar' => 'دبابغ', 'mail' => 'dabbagh'],
    ];

    /**
     * @var list<array{line_one: string, line_two: string|null, city: string, state: string}>
     */
    private const ADDRESSES_EN = [
        ['line_one' => '12 Al-Manara Street', 'line_two' => 'Al-Baloum', 'city' => 'Ramallah', 'state' => 'Ramallah and Al-Bireh'],
        ['line_one' => '45 Ein Sara Street', 'line_two' => 'Al-Tuffah', 'city' => 'Gaza', 'state' => 'Gaza'],
        ['line_one' => '8 Rafidia Square', 'line_two' => 'Rafidia', 'city' => 'Nablus', 'state' => 'Nablus'],
        ['line_one' => '23 King Hussein Street', 'line_two' => 'City Centre', 'city' => 'Hebron', 'state' => 'Hebron'],
        ['line_one' => '6 Manger Street', 'line_two' => 'Old City', 'city' => 'Bethlehem', 'state' => 'Bethlehem'],
        ['line_one' => '31 Jerusalem Road', 'line_two' => 'Al-Shaaf', 'city' => 'Gaza', 'state' => 'Gaza'],
        ['line_one' => '17 Al-Tariq Street', 'line_two' => 'Askar', 'city' => 'Nablus', 'state' => 'Nablus'],
        ['line_one' => '9 An-Najah Street', 'line_two' => 'Al-Masayeb', 'city' => 'Jenin', 'state' => 'Jenin'],
        ['line_one' => '54 Jordan Valley Road', 'line_two' => 'Al-Jalazoun', 'city' => 'Ramallah', 'state' => 'Ramallah and Al-Bireh'],
        ['line_one' => '3 Al-Wahda Street', 'line_two' => 'Al-Shujaya', 'city' => 'Gaza', 'state' => 'Gaza'],
        ['line_one' => '28 Al-Quds Street', 'line_two' => 'Azza Camp', 'city' => 'Bethlehem', 'state' => 'Bethlehem'],
        ['line_one' => '71 Al-Amal Street', 'line_two' => 'Al-Aroub', 'city' => 'Hebron', 'state' => 'Hebron'],
    ];

    /**
     * @var list<array{line_one: string, line_two: string|null, city: string, state: string}>
     */
    private const ADDRESSES_AR = [
        ['line_one' => '12 شارع المنارة', 'line_two' => 'البلوعم', 'city' => 'رام الله', 'state' => 'رام الله والبيرة'],
        ['line_one' => '45 شارع عين سارة', 'line_two' => 'التفاح', 'city' => 'غزة', 'state' => 'غزة'],
        ['line_one' => '8 ساحة رافيدية', 'line_two' => 'رافيدية', 'city' => 'نابلس', 'state' => 'نابلس'],
        ['line_one' => '23 شارع الملك حسين', 'line_two' => 'وسط البلد', 'city' => 'الخليل', 'state' => 'الخليل'],
        ['line_one' => '6 شارع المهدية', 'line_two' => 'البلدة القديمة', 'city' => 'بيت لحم', 'state' => 'بيت لحم'],
        ['line_one' => '31 شارع القدس', 'line_two' => 'الشافع', 'city' => 'غزة', 'state' => 'غزة'],
        ['line_one' => '17 شارع الطريق', 'line_two' => 'عسكر', 'city' => 'نابلس', 'state' => 'نابلس'],
        ['line_one' => '9 شارع النجاح', 'line_two' => 'المعايب', 'city' => 'جنين', 'state' => 'جنين'],
        ['line_one' => '54 شارع وادي الأردن', 'line_two' => 'الجلزون', 'city' => 'رام الله', 'state' => 'رام الله والبيرة'],
        ['line_one' => '3 شارع الوحدة', 'line_two' => 'الشجاعية', 'city' => 'غزة', 'state' => 'غزة'],
        ['line_one' => '28 شارع القدس', 'line_two' => 'مخيم عزة', 'city' => 'بيت لحم', 'state' => 'بيت لحم'],
        ['line_one' => '71 شارع الأمل', 'line_two' => 'العروب', 'city' => 'الخليل', 'state' => 'الخليل'],
    ];
}
