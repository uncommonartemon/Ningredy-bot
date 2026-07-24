<?php

namespace Tests\Unit;

use App\Services\Products\ProductIdentityKey;
use Tests\TestCase;

class ProductIdentityKeyTest extends TestCase
{
    public function test_it_slugs_brand_and_model(): void
    {
        $this->assertSame(
            'lenovo-legion-5-16irx9',
            ProductIdentityKey::for('Lenovo', 'Legion 5 16IRX9', 'Lenovo Legion 5 16IRX9'),
        );
    }

    public function test_it_falls_back_to_title_when_model_is_missing(): void
    {
        $this->assertSame(
            'asus-rog-nuc-2025',
            ProductIdentityKey::for('ASUS', null, 'ROG NUC (2025)'),
        );
    }

    public function test_same_brand_and_model_produce_the_same_key_regardless_of_case(): void
    {
        $this->assertSame(
            ProductIdentityKey::for('Lenovo', 'Legion 5', 'x'),
            ProductIdentityKey::for('LENOVO', 'legion 5', 'y'),
        );
    }
}
