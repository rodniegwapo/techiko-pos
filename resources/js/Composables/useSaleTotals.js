import { computed, unref } from 'vue'

const defaultSalesSettings = {
  apply_vat_automatically: false,
  vat_rate_percent: 12,
  vat_pricing_mode: 'exclusive',
}

export function useSaleTotals({
  orders,
  orderDiscountAmount,
  salesSettings,
  currentSale,
  salesCartIsOnline,
}) {
  const salesSettingsResolved = computed(
    () => unref(salesSettings) ?? defaultSalesSettings,
  )

  const totalAmount = computed(() => {
    const list = unref(orders) ?? []
    return list.reduce((sum, order) => {
      const price = parseFloat(order.price) || 0
      const quantity = parseInt(order.quantity, 10) || 0
      const subtotal = !Number.isNaN(price * quantity)
        ? price * quantity
        : quantity * price
      return sum + subtotal
    }, 0)
  })

  const itemCount = computed(() => {
    const list = unref(orders) ?? []
    return list.reduce(
      (sum, order) => sum + (parseInt(order.quantity, 10) || 0),
      0,
    )
  })

  const isInclusive = computed(
    () => salesSettingsResolved.value.vat_pricing_mode === 'inclusive',
  )

  const netAfterOrderDiscount = computed(() =>
    Math.max(
      0,
      Number(totalAmount.value) - (parseFloat(unref(orderDiscountAmount)) || 0),
    ),
  )

  const taxAmountDisplay = computed(() => {
    if (!salesSettingsResolved.value.apply_vat_automatically) {
      return 0
    }
    const sale = unref(currentSale)
    const online = unref(salesCartIsOnline)
    if (online && sale && sale.tax_amount != null) {
      return Number(sale.tax_amount) || 0
    }
    const rate =
      (Number(salesSettingsResolved.value.vat_rate_percent) || 12) / 100
    const net = netAfterOrderDiscount.value
    if (isInclusive.value) {
      return Math.round(net * (rate / (1 + rate)) * 100) / 100
    }
    return Math.round(net * rate * 100) / 100
  })

  const grandTotalDisplay = computed(() => {
    const sale = unref(currentSale)
    const online = unref(salesCartIsOnline)
    if (online && sale && sale.grand_total != null) {
      return Number(sale.grand_total) || 0
    }
    if (
      salesSettingsResolved.value.apply_vat_automatically &&
      isInclusive.value
    ) {
      return netAfterOrderDiscount.value
    }
    return netAfterOrderDiscount.value + taxAmountDisplay.value
  })

  const netExVatDisplay = computed(() => {
    if (!salesSettingsResolved.value.apply_vat_automatically) {
      return 0
    }
    if (!isInclusive.value) {
      return 0
    }
    return Math.max(
      0,
      Number(grandTotalDisplay.value) - Number(taxAmountDisplay.value),
    )
  })

  return {
    salesSettingsResolved,
    totalAmount,
    itemCount,
    isInclusive,
    netAfterOrderDiscount,
    taxAmountDisplay,
    grandTotalDisplay,
    netExVatDisplay,
  }
}
