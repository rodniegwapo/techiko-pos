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
                <span>Account Settings </span>
            </template>

            <a-dropdown :trigger="['click']">
                <a-avatar
                    size="large"
                    :src="`https://ui-avatars.com/api/?name=${user.name}&background=287e47&color=ffff`"
                    class="cursor-pointer"
                >
                </a-avatar>

                <template #overlay >
                    <a-menu>
                        <a-menu-item title="Account Settings">
                            <Link href="account-settings.index"
                                ><SettingOutlined />
                                    Account Settings
                                </Link
                            >
                        </a-menu-item>
                        <Link
                            href="logout"
                            method="post"
                            as="button"
                            type="button"
                            class="block w-full text-left"
                        >
                            <a-menu-item title="Logout">
                                <LogoutOutlined /> Logout
                            </a-menu-item>
                        </Link>
                    </a-menu>
                </template>
            </a-dropdown>
        </a-tooltip>
    </div>
</template>
