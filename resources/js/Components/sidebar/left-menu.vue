<script setup>
import { ref, computed, watch } from 'vue'
import { IconDashboard,IconCategory,IconUsers ,IconBrandProducthunt} from '@tabler/icons-vue'


const emit = defineEmits(['handleClick'])

const menus = computed(() => {  
    return [
        {
            title: 'Dashboard',
            path: '/dashboard',
            icon: IconDashboard,
           
        },
        {
            title: 'Categories',
            path: '/orders',
            icon: IconCategory,
          
        },
        {
            title: 'Customers',
            path: '/customers',
            icon: IconUsers,
      
        },
        {
            title: 'Products',
            path: '/products',
            icon: IconBrandProducthunt,
            // children: [
            //     {
            //         title: 'products.products',
            //         path: '/products/products',
            //     },
            //     {
            //         title: 'products.orders',
            //         path: '/products/orders',
            //     },
            //     {
            //         title: 'products.customers',
            //         path: '/products/customers',
            //     },
            // ],
        },
    ]
})  


let openKeys = ref([])

const menuSelectedKeys = ref([])

</script>

<template>
    <div class="overflow-auto ">
        <a-menu
            v-model:openKeys="openKeys"
            v-model:selectedKeys="menuSelectedKeys"
           
            mode="inline"
        >
            <template v-for="menu in menus">
                <a-menu-item
                    v-if="!menu.children"
                    :key="menu.path"
                    @click="emit('handleClick', menu)"
                    class="font-semibold text-gray-800 items-center"
                >
                    <template #icon>
                        <span
                            class="leading-[40px] h-full items-center flex justify-center"
                        >
                            <component
                                class="flex-shrink-0"
                                v-if="menu.icon"
                                :is="menu.icon"
                            />
                        </span>
                    </template>
                    {{ menu.title }}
                </a-menu-item>
                <a-sub-menu
                    v-else
                    :key="'sub' + menu.path"
                    class="font-semibold text-gray-800 items-center"
                >
                    <template #icon>
                        <span
                            class="leading-[40px] h-full items-center flex justify-center"
                        >
                            <component
                                class="flex-shrink-0"
                                v-if="menu.icon"
                                :is="menu.icon"
                            />
                        </span>
                    </template>
                    <template #title>{{ menu.title}}</template>
                    <a-menu-item
                        v-for="child in menu.children"
                        :key="child.path"
                        @click="emit('handleClick', child, menu)"
                    >
                        <component
                            v-if="child.icon"
                            :is="child.icon"
                            style="font-size: 20px"
                        >
                        </component>
                        <span>{{ child.title}}</span>
                    </a-menu-item>
                </a-sub-menu>
            </template>
        </a-menu>
    </div>
</template>
