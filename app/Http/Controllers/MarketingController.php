<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function home(): Response
    {
        return $this->render('Marketing/Home', 'Modern point of sale for growing businesses', 'Streamline sales, inventory, and loyalty in one place. A fast, reliable POS built for teams that need to move quickly.', '/');
    }

    public function services(): Response
    {
        return $this->render(
            'Marketing/Services',
            'Services & capabilities',
            'Point of sale, inventory, customer loyalty, reporting, and multi-location tools designed for retail and hospitality.',
            '/services'
        );
    }

    public function about(): Response
    {
        return $this->render(
            'Marketing/About',
            'About us',
            'We build point-of-sale software that is fast to use, easy to run, and ready to scale with your business.',
            '/about'
        );
    }

    public function contact(): Response
    {
        return $this->render(
            'Marketing/Contact',
            'Contact',
            'Get in touch about Techiko POS. We respond to product and partnership questions from this page.',
            '/contact'
        );
    }

    public function pricing(): Response
    {
        return $this->render(
            'Marketing/Pricing',
            'Pricing',
            'Free, Professional, and Business plans in Philippine Pesos (PHP). Start free with 100 products, or scale with unlimited inventory and stores.',
            '/pricing'
        );
    }

    private function render(string $component, string $title, string $description, string $path): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        return Inertia::render($component, [
            'seo' => [
                'title' => $title,
                'description' => $description,
                'path' => $path,
                'ogImage' => $base.'/images/og-default.png',
            ],
        ]);
    }
}
