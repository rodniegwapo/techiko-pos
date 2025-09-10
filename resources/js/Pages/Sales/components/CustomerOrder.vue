<script setup>
import { ref, inject } from "vue";
import {
  CloseOutlined,
  PlusSquareOutlined,
  MinusSquareOutlined,
} from "@ant-design/icons-vue";

import { useOrders } from "@/Composables/useOrder";

const { orders,handleAddOrder,handleSubtractOrder,totalAmount,formattedTotal, removeOrder } = useOrders();
</script>

<template>
  <div class="space-y-2">
    <div class="font-semibold text-lg">Current Order</div>
    <a-input value="Walk-in Customer" disabled />
  </div>
  <div
     class="flex flex-col gap-2 mt-2 h-[calc(100vh-380px)] overflow-auto overflow-x-hidden"
  >
    <div
      v-for="(order, index) in orders"
      :key="index"
      class="flex justify-between items-center border px-4 rounded-lg bg-white hover:shadow cursor-pointer"
    >
      <div>
        <div class="text-sm font-semibold">{{ order.name }}</div>

        <div
          class="text-xs flex items-center bg-transparent text-gray-800 border-none shadow-none gap-1 mt-1"
        >
          <PlusSquareOutlined @click="handleAddOrder(order)" />
          <span>{{ order.quantity }}</span>
          <MinusSquareOutlined  @click="handleSubtractOrder(order)"/>
        </div>
      </div>
      <div class="text-right">
        <div class="text-red-600 mt-1 cursor-pointer" @click="removeOrder(order.id)">
          <CloseOutlined />
        </div>
        <div class="text-xs text-green-700 mt-1">{{ order.price }}</div>
      </div>
    </div>
  </div>
  <hr class="-mx-6 border-t-[3px] pt-2 mt-2" />
  <div class="font-bold text-lg">
    Total: <span class="text-green-700">{{ formattedTotal }}</span>
  </div>
  <div class="mt-2">
    <div>Payment Method</div>
    <a-input value="Pay in Cash " disabled></a-input>
  </div>
  <div>
    <a-button
      class="w-full mt-2 bg-green-700 border-green-700 hover:bg-green-600"
      type="primary"
      >Proceed Payment</a-button
    >
  </div>
</template>