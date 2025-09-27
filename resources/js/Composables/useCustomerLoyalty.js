import { ref, computed } from 'vue';
import { notification } from 'ant-design-vue';
import axios from 'axios';

export function useCustomerLoyalty() {
    const selectedCustomer = ref(null);
    const isLoyalCustomer = ref(false);
    
    // Calculate points for a purchase amount
    const calculatePoints = (amount, customer = null) => {
        const targetCustomer = customer || selectedCustomer.value;
        if (!targetCustomer || !amount) return 0;
        
        const basePoints = Math.floor(amount / 10); // 1 point per ₱10
        const multiplier = targetCustomer.tier_info?.multiplier || 1;
        
        return Math.floor(basePoints * multiplier);
    };
    
    // Process loyalty rewards after sale completion
    const processLoyaltyRewards = async (saleId, saleAmount) => {
        if (!selectedCustomer.value || !saleAmount) return null;
        
        try {
            const response = await axios.post(`/api/sales/${saleId}/process-loyalty`, {
                customer_id: selectedCustomer.value.id,
                sale_amount: saleAmount
            });
            
            const results = response.data;
            
            // Update local customer data
            if (results.points_earned) {
                selectedCustomer.value.loyalty_points += results.points_earned;
                
                notification.success({
                    message: 'Loyalty Points Earned!',
                    description: `${selectedCustomer.value.name} earned ${results.points_earned} points!`,
                    duration: 5,
                });
            }
            
            // Handle tier upgrade
            if (results.tier_upgraded) {
                selectedCustomer.value.tier = results.new_tier;
                
                notification.success({
                    message: 'Tier Upgraded!',
                    description: `Congratulations! ${selectedCustomer.value.name} is now ${results.new_tier.toUpperCase()} tier!`,
                    duration: 8,
                });
            }
            
            return results;
        } catch (error) {
            console.error('Loyalty processing error:', error);
            notification.error({
                message: 'Loyalty Processing Failed',
                description: 'Points could not be awarded for this purchase.',
            });
            return null;
        }
    };
    
    // Get tier progress information
    const getTierProgress = computed(() => {
        if (!selectedCustomer.value) return null;
        
        const currentSpent = selectedCustomer.value.lifetime_spent || 0;
        const thresholds = {
            bronze: 0,
            silver: 20000,
            gold: 50000,
            platinum: 100000
        };
        
        const currentTier = selectedCustomer.value.tier || 'bronze';
        const nextTier = {
            bronze: 'silver',
            silver: 'gold',
            gold: 'platinum',
            platinum: null
        }[currentTier];
        
        if (!nextTier) {
            return {
                current_tier: currentTier,
                next_tier: null,
                progress_percentage: 100,
                amount_needed: 0
            };
        }
        
        const nextThreshold = thresholds[nextTier];
        const currentThreshold = thresholds[currentTier];
        const progress = ((currentSpent - currentThreshold) / (nextThreshold - currentThreshold)) * 100;
        
        return {
            current_tier: currentTier,
            next_tier: nextTier,
            progress_percentage: Math.min(100, Math.max(0, progress)),
            amount_needed: Math.max(0, nextThreshold - currentSpent)
        };
    });
    
    // Clear selected customer
    const clearCustomer = () => {
        selectedCustomer.value = null;
        isLoyalCustomer.value = false;
    };
    
    // Set customer
    const setCustomer = (customer) => {
        selectedCustomer.value = customer;
        isLoyalCustomer.value = true;
    };
    
    return {
        selectedCustomer,
        isLoyalCustomer,
        calculatePoints,
        processLoyaltyRewards,
        getTierProgress,
        clearCustomer,
        setCustomer,
    };
}
