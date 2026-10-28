<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public policy document, addressed by slug.
 *
 * Payment gateways require Privacy, Refund/Cancellation and Shipping policies
 * to be readable without logging in. The admin edits them in one place and
 * both the website and the app render the same content.
 */
class PolicyPage extends Model
{
    const SLUG_PRIVACY  = 'privacy-policy';
    const SLUG_REFUND   = 'refund-policy';
    const SLUG_SHIPPING = 'shipping-policy';
    const SLUG_CONTACT  = 'contact-us';

    /** Every slug the admin can edit, in menu order. */
    public const SLUGS = [
        self::SLUG_PRIVACY,
        self::SLUG_REFUND,
        self::SLUG_SHIPPING,
        self::SLUG_CONTACT,
    ];

    protected $fillable = [
        'slug',
        'title',
        'content',
        'is_active',
        'is_deleted',
        'created_by',
        'updated_by',
    ];

    public static function findBySlug(string $slug): ?self
    {
        return self::where('slug', $slug)->where('is_deleted', 0)->first();
    }

    /**
     * Content seeded on first migrate. Written from what the platform
     * actually does, with the company details, support hours, refund,
     * cancellation and delivery rules confirmed by the business on
     * 26 October 2026. GSTIN, PAN and CIN are deliberately not published.
     */
    public static function defaults(): array
    {
        // Confirmed by the business on 26 October 2026. GSTIN, PAN and CIN are
        // deliberately NOT published on these pages.
        $company = 'Star Up World Marketing Private Limited';
        $address = 'SF No 140/2A2, Maniyarakuppam X Road, Ambur, Vaniyambadi, Vellore – 635802, Tamil Nadu, India';
        $phone   = '+91 8903153777';
        $email   = 'starupworldmarketing@gmail.com';
        $hours   = '10:00 AM to 7:00 PM, all 7 days';
        $officer = 'Dinesh Babu';
        $updated = 'This policy was last updated on 26 October 2026.';

        return [
            self::SLUG_PRIVACY => [
                'title'   => 'Privacy Policy',
                'content' => <<<HTML
<p>{$company} ("we", "us", "the Company") operates the Starup platform — the Starup website and the Starup mobile application. This policy explains what personal information we collect, why we collect it, and the choices you have. {$updated}</p>

<h3>Information we collect</h3>
<ul>
<li><strong>Account details</strong> — your name, mobile number, email address, date of birth and the referral code used when you register.</li>
<li><strong>Address details</strong> — city, district, state and pincode, and a delivery address when you ask for a product to be delivered.</li>
<li><strong>Identity and documents</strong> — documents you upload to your profile when these are required for verification or payouts.</li>
<li><strong>Bank details</strong> — account number, IFSC and account holder name, collected only to pay out your withdrawals.</li>
<li><strong>Activity on the platform</strong> — training and promotion videos you watch, quiz results, referrals, earnings, scratch cards, plan purchases and withdrawal requests.</li>
<li><strong>Device and usage information</strong> — IP address, device model, browser and the approximate location derived from your IP address, recorded when you sign in so we can protect your account.</li>
<li><strong>Payment information</strong> — the amount, order reference and the result of each payment. Card numbers, UPI PINs, net-banking passwords and similar credentials are entered on the payment gateway's own secure page. <strong>We never see or store them.</strong></li>
</ul>

<h3>How we use your information</h3>
<ul>
<li>To create and operate your account and keep you signed in securely.</li>
<li>To run the training programme, record your progress and calculate your earnings.</li>
<li>To process plan payments, dispatch products and pay out your withdrawals.</li>
<li>To contact you about your account, orders and support requests.</li>
<li>To detect and prevent fraud and misuse, and to meet our legal and tax obligations.</li>
</ul>

<h3>When we share information</h3>
<p>We do not sell your personal information. We share it only with:</p>
<ul>
<li><strong>Payment gateways and banks</strong>, to process your payments and payouts.</li>
<li><strong>Courier and logistics partners</strong>, to deliver products you have ordered.</li>
<li><strong>Service providers</strong> who host and support the platform, under confidentiality obligations.</li>
<li><strong>Government or law-enforcement authorities</strong>, where we are required to do so by law.</li>
</ul>

<h3>How long we keep it</h3>
<p>We keep your account information for as long as your account is active, and afterwards only for as long as the law requires us to retain transaction and tax records.</p>

<h3>How we protect it</h3>
<p>Data is transmitted over encrypted (HTTPS) connections. Passwords are stored in hashed form, access to administrative systems is restricted, and sign-ins are limited to one device at a time. No system can be guaranteed completely secure, but we work to protect your information.</p>

<h3>Your choices</h3>
<p>You may review and update your profile details in the app at any time. To request a copy of your data, correction of an error, or deletion of your account, contact us using the details below. Some information must be retained where the law requires it.</p>

<h3>Children</h3>
<p>The platform is intended for users aged 18 and above. We do not knowingly collect information from children.</p>

<h3>Changes to this policy</h3>
<p>If we change this policy we will publish the updated version on this page and update the date above.</p>

<h3>Contact us</h3>
<p>{$company}<br>{$address}<br>Phone: {$phone}<br>Email: {$email}</p>
<p>Grievance Officer: {$officer}, {$email}. We acknowledge every grievance and aim to resolve it within 30 days.</p>
HTML,
            ],

            self::SLUG_REFUND => [
                'title'   => 'Refund & Cancellation Policy',
                'content' => <<<HTML
<p>This policy explains when a payment made to {$company} can be cancelled or refunded. {$updated}</p>

<h3>What you pay for</h3>
<p>Payments on the Starup platform are made to activate a promoter plan. Each plan includes the product pack for that level, which is then dispatched to you or kept ready for pickup.</p>

<h3>Cancellation</h3>
<ul>
<li>You may cancel a plan order <strong>before the activation PIN has been used</strong> by contacting our support team with your order reference.</li>
<li>Once the activation PIN has been used to activate the plan, the order <strong>cannot be cancelled</strong>.</li>
</ul>

<h3>Refunds</h3>
<ul>
<li><strong>Failed payments.</strong> If an amount is debited from your account but the order does not complete, the amount is normally reversed by your bank automatically within 5–7 working days. If it is not, contact us and we will trace it with the payment gateway.</li>
<li><strong>Duplicate payments.</strong> If you are charged twice for the same order, the extra amount is refunded in full.</li>
<li><strong>Cancelled orders.</strong> Where a cancellation is accepted before the activation PIN is used, the amount is refunded in full.</li>
<li><strong>Damaged or wrong product.</strong> Report it within 7 days of delivery with photographs, and we will arrange a replacement or a refund after verification.</li>
<li>Earnings, bonuses and scratch-card amounts credited inside the platform are not payments to us and are not covered by this policy. They are withdrawn through the withdrawal process.</li>
</ul>

<h3>How refunds are paid</h3>
<p>Approved refunds are credited to the <strong>same payment method used for the original payment</strong>. Once we approve a refund it is initiated within 7 working days, and your bank may take a further 5–7 working days to credit it.</p>

<h3>How to request a refund</h3>
<p>Write to us at {$email} or call {$phone} ({$hours}) with your registered mobile number, the order reference and the date and amount of the payment. You can find the order reference in the app under your plan or payment history.</p>

<h3>Contact us</h3>
<p>{$company}<br>{$address}<br>Phone: {$phone}<br>Email: {$email}</p>
HTML,
            ],

            self::SLUG_SHIPPING => [
                'title'   => 'Shipping & Delivery Policy',
                'content' => <<<HTML
<p>This policy explains how {$company} delivers the products included with a promoter plan. {$updated}</p>

<h3>What is delivered</h3>
<p>Each promoter plan includes a product pack for that level. The quantity included depends on the plan you activate and is shown in the app before you pay.</p>

<h3>Delivery or pickup</h3>
<p>When you activate a plan you choose one of:</p>
<ul>
<li><strong>Delivery</strong> — we send the product to the address you provide.</li>
<li><strong>Pickup</strong> — you collect the product from the company location on the date shown in the app.</li>
</ul>

<h3>Timelines</h3>
<ul>
<li>Orders are processed after the payment is confirmed and the plan is activated.</li>
<li>Delivery normally takes <strong>10 to 15 days</strong> from dispatch, depending on your location.</li>
<li>Delays can occur due to courier issues, weather, strikes or events outside our control. We will keep you informed where this happens.</li>
</ul>

<h3>Delivery charges</h3>
<p><strong>Delivery is free.</strong> The price of the plan includes delivery of the product pack to the address you provide.</p>

<h3>Tracking your order</h3>
<p>Once your order is dispatched, the dispatch details and tracking reference are shown in the app, and an invoice is available for download.</p>

<h3>Address accuracy</h3>
<p>Please make sure your delivery address and mobile number are correct and complete, so your order reaches you without delay.</p>

<h3>If your order does not arrive</h3>
<p>If your order has not reached you within the timeline above, contact us with your order reference and we will trace it with the courier.</p>

<h3>Contact us</h3>
<p>{$company}<br>{$address}<br>Phone: {$phone}<br>Email: {$email}</p>
HTML,
            ],

            self::SLUG_CONTACT => [
                'title'   => 'Contact Us',
                'content' => <<<HTML
<p>We are happy to help with anything to do with your account, a payment, an order or a withdrawal.</p>

<h3>Registered office</h3>
<p>{$company}<br>{$address}</p>

<h3>Reach us</h3>
<ul>
<li><strong>Phone:</strong> {$phone}</li>
<li><strong>Email:</strong> {$email}</li>
<li><strong>Support hours:</strong> {$hours}</li>
</ul>

<h3>Support inside the app</h3>
<p>You can also raise a request from the Starup app under <strong>Support &amp; Help</strong> or <strong>Contact Us</strong>, and we will reply to your registered mobile number or email.</p>
HTML,
            ],
        ];
    }
}
