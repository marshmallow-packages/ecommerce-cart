<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Enums;

enum DiscountEligibility: string
{
    case All = 'all';
    case Customers = 'eligible_for_customers';
    case Emails = 'eligible_for_emails';
}
