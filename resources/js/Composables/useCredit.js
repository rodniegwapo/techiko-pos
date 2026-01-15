import { ref } from 'vue';
import { notification } from 'ant-design-vue';
import axios from 'axios';
import { useDomainRoutes } from './useDomainRoutes';

export function useCredit() {
    const { getRoute } = useDomainRoutes();
    const loading = ref(false);

    /**
     * Get customer credit information
     */
    const getCustomerCredit = async (customerId) => {
        try {
            loading.value = true;
            const response = await axios.get(
                getRoute('credits.show', { customer: customerId })
            );
            return response.data;
        } catch (error) {
            console.error('Error fetching customer credit:', error);
            notification.error({
                message: 'Error',
                description: 'Failed to fetch customer credit information',
            });
            throw error;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Record a payment
     */
    const recordPayment = async (customerId, data) => {
        try {
            loading.value = true;
            const response = await axios.post(
                getRoute('credits.transactions.store', { customer: customerId }),
                data
            );

            notification.success({
                message: 'Success',
                description: 'Payment recorded successfully',
            });

            return response.data;
        } catch (error) {
            console.error('Error recording payment:', error);
            const message = error.response?.data?.message || 'Failed to record payment';
            notification.error({
                message: 'Error',
                description: message,
            });
            throw error;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Check if customer has available credit
     */
    const checkCreditAvailability = async (customerId, amount) => {
        try {
            const creditInfo = await getCustomerCredit(customerId);
            const customer = creditInfo.customer || creditInfo;
            const availableCredit = customer.available_credit || customer.getAvailableCredit?.() || 0;
            
            return {
                available: availableCredit >= amount,
                availableCredit,
                creditLimit: customer.credit_limit || 0,
                creditBalance: customer.credit_balance || 0,
                creditEnabled: customer.credit_enabled || false,
            };
        } catch (error) {
            console.error('Error checking credit availability:', error);
            return {
                available: false,
                availableCredit: 0,
                creditLimit: 0,
                creditBalance: 0,
                creditEnabled: false,
            };
        }
    };

    /**
     * Get overdue accounts
     */
    const getOverdueAccounts = async () => {
        try {
            loading.value = true;
            const response = await axios.get(
                getRoute('credits.overdue')
            );
            return response.data.data || [];
        } catch (error) {
            console.error('Error fetching overdue accounts:', error);
            return [];
        } finally {
            loading.value = false;
        }
    };

    /**
     * Update credit settings
     */
    const updateCreditSettings = async (customerId, settings) => {
        try {
            loading.value = true;
            const response = await axios.put(
                getRoute('credits.settings.update', { customer: customerId }),
                settings
            );

            notification.success({
                message: 'Success',
                description: 'Credit settings updated successfully',
            });

            return response.data;
        } catch (error) {
            console.error('Error updating credit settings:', error);
            const message = error.response?.data?.message || 'Failed to update credit settings';
            notification.error({
                message: 'Error',
                description: message,
            });
            throw error;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Get credit history
     */
    const getCreditHistory = async (customerId, filters = {}) => {
        try {
            loading.value = true;
            const response = await axios.get(
                getRoute('credits.history', { customer: customerId }),
                { params: filters }
            );
            return response.data.data || [];
        } catch (error) {
            console.error('Error fetching credit history:', error);
            return [];
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        getCustomerCredit,
        recordPayment,
        checkCreditAvailability,
        getOverdueAccounts,
        updateCreditSettings,
        getCreditHistory,
    };
}
