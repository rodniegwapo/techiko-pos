<template>
    <footer class="bg-white border-t mt-12">
        <div class="max-w-7xl mx-auto px-4 py-8">
            <!-- Main Footer Content -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
                <!-- Company Information -->
                <div>
                    <h3 class="font-bold text-gray-900 text-lg mb-2">
                        Techicko
                    </h3>
                    <p class="text-gray-600 text-sm">
                        Your trusted partner for quality system services.
                    </p>
                </div>

                <!-- Support Links -->
                <div>
                    <h3 class="font-bold text-gray-900 text-lg mb-3">
                        Support
                    </h3>
                    <div class="space-y-2">
                        <a
                            href="#"
                            class="block text-gray-600 text-sm hover:text-blue-600 transition-colors"
                        >
                            Track Order
                        </a>
                        <a
                            href="#"
                            class="block text-gray-600 text-sm hover:text-blue-600 transition-colors"
                        >
                            Help Center
                        </a>
                        <a
                            href="#"
                            class="block text-gray-600 text-sm hover:text-blue-600 transition-colors"
                        >
                            Contact Us
                        </a>
                    </div>
                </div>

                <!-- Order Information -->
                <div>
                    <h3 class="font-bold text-gray-900 text-lg mb-3">
                        Order Information
                    </h3>
                    <div class="space-y-1">
                        <p class="text-gray-600 text-sm">
                            Order #{{ orderId || "N/A" }}
                        </p>
                        <p class="text-gray-600 text-sm">
                            Last updated: {{ lastUpdated }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Separator Line -->
            <div class="border-t border-gray-200 mb-6"></div>

            <!-- Bottom Bar -->
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="text-gray-600 text-sm mb-4 md:mb-0">
                    © 2025 Techicko. All rights reserved.
                </div>
                <div class="flex space-x-6">
                    <a
                        href="#"
                        class="text-gray-600 text-sm hover:text-blue-600 transition-colors"
                    >
                        Privacy Policy
                    </a>
                    <a
                        href="#"
                        class="text-gray-600 text-sm hover:text-blue-600 transition-colors"
                    >
                        Terms of Service
                    </a>
                </div>
            </div>
        </div>
    </footer>
</template>

<script setup>
defineProps({
    orderId: {
        type: [String, Number],
        default: null,
    },
    lastUpdated: {
        type: String,
        required: true,
    },
});
</script>
