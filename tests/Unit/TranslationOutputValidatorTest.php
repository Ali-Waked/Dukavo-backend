<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Translation\TranslationOutputValidator;
use PHPUnit\Framework\TestCase;

/**
 * The invariant checker that stands between a model answer and the review table.
 *
 * A translation that reads well but corrupts a placeholder, a price, a SKU or
 * the HTML structure is unusable. These tests pin each invariant individually
 * so a future prompt change cannot quietly weaken the server-side guarantee.
 */
class TranslationOutputValidatorTest extends TestCase
{
    private TranslationOutputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new TranslationOutputValidator;
    }

    public function test_a_faithful_translation_passes(): void
    {
        $errors = $this->validator->validate(
            'Hello {{customer_name}}, your order #A-104 costs $19.99. Visit https://dukavo.com/help.',
            'مرحبا {{customer_name}}، طلبك #A-104 يكلف $19.99. زر https://dukavo.com/help.',
        );

        $this->assertSame([], $errors);
    }

    public function test_a_dropped_placeholder_is_an_error(): void
    {
        $errors = $this->validator->validate(
            'Hello {{customer_name}}, welcome back.',
            'مرحبا، أهلا بعودتك.',
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('{{customer_name}}', $errors[0]);
    }

    public function test_a_changed_number_is_an_error(): void
    {
        $errors = $this->validator->validate(
            'Delivery takes 3 to 5 days.',
            'يستغرق التوصيل من ٣ إلى ٥ أيام.',
        );

        $this->assertNotEmpty($errors);
    }

    public function test_a_changed_url_is_an_error(): void
    {
        $errors = $this->validator->validate(
            'Read more at https://dukavo.com/policy.',
            'اقرأ المزيد على https://dukavo.com/siyasa.',
        );

        $this->assertNotEmpty($errors);
    }

    public function test_a_dropped_sku_is_an_error(): void
    {
        $errors = $this->validator->validate(
            'Replacement part SKU-4410 is included.',
            'قطعة الغيار مشمولة.',
        );

        $this->assertNotEmpty($errors);
    }

    public function test_a_protected_brand_name_must_survive(): void
    {
        $errors = $this->validator->validate(
            'The Dukavo widget is ready.',
            'القطعة جاهزة.',
            'text',
            ['Dukavo'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Dukavo', $errors[0]);
    }

    public function test_html_structure_must_survive(): void
    {
        $errors = $this->validator->validate(
            '<p>Hello</p><strong>Details</strong>',
            '<p>مرحبا</p>',
            'html',
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('<strong>', implode(' ', $errors));
    }

    public function test_html_that_introduces_a_script_tag_is_rejected(): void
    {
        $errors = $this->validator->validate(
            '<p>Hello</p>',
            '<p>مرحبا</p><script>alert(1)</script>',
            'html',
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('disallowed', $errors[0]);
    }

    public function test_an_empty_translation_is_an_error(): void
    {
        $this->assertNotSame([], $this->validator->validate('Hello', '   '));
    }

    public function test_validate_many_reports_each_bad_field(): void
    {
        $errors = $this->validator->validateMany(
            ['name' => 'Order {{code}}', 'description' => 'Costs $10.'],
            ['name' => 'طلب', 'description' => 'يكلف $10.'],
            ['name' => 'text', 'description' => 'text'],
        );

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayNotHasKey('description', $errors);
    }
}
