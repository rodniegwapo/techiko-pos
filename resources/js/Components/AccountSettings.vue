<script setup>
import { LogoutOutlined, SettingOutlined } from '@ant-design/icons-vue'

const props = defineProps({
    user: {
        type: Object,
        default: {}
    }
})
</script>

<template>
    <div>
        <a-tooltip placement="left">
            <template #title>
                <span>{{ $t('Account Settings') }} </span>
            </template>

            <a-dropdown :trigger="['click']">
                <a-avatar
                    size="large"
                    :src="`https://ui-avatars.com/api/?name=${user.name}&background=1f9bf4&color=ffff`"
                    class="cursor-pointer"
                >
                </a-avatar>

                <template #overlay class="mx-2">
                    <a-menu>
                        <a-menu-item :title="$t('Account Settings')">
                            <Link :href="route('account-settings.index')"
                                ><SettingOutlined />{{
                                    $t('Account Settings')
                                }}</Link
                            >
                        </a-menu-item>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            type="button"
                            class="block w-full text-left"
                        >
                            <a-menu-item title="Logout">
                                <LogoutOutlined /> {{ $t('Logout') }}
                            </a-menu-item>
                        </Link>
                    </a-menu>
                </template>
            </a-dropdown>
        </a-tooltip>
    </div>
</template>
