import { ref, computed, watch } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import { notification } from 'ant-design-vue'

export function useGlobalLocation() {
    const page = usePage()
    
    // Global location state
    const selectedLocationId = ref(null)
    const locations = ref([])
    
    // Initialize from page props
    const initializeLocation = () => {
        // Check for preferred location in localStorage first, then current location
        const preferredLocationId = localStorage.getItem('preferred_location_id')
        
        selectedLocationId.value = preferredLocationId || page.props.filters?.location_id || page.props.currentLocation?.id || null
        
        // Ensure locations is always an array
        const locationsData = page.props.locations || []
        const processedLocations = Array.isArray(locationsData) ? locationsData : Object.values(locationsData)
        
        // Handle both raw data and resource format
        locations.value = processedLocations.map(location => {
            // If it's already in resource format, use as is
            if (location.name && location.address !== undefined) {
                return location
            }
            
            // If it's raw data, map to expected format
            return {
                id: location.id,
                name: location.name || 'Unknown Location',
                address: location.address || 'No address',
                type: location.type || 'store',
                code: location.code || '',
                is_active: location.is_active !== undefined ? location.is_active : true,
                is_default: location.is_default || false,
                domain: location.domain || null
            }
        })
        
        // Debug logging (remove in production)
        // console.log('Raw locations data:', locationsData)
        // console.log('Processed locations:', locations.value)
        // console.log('First location:', locations.value[0])
        // console.log('Selected location ID:', selectedLocationId.value)
    }
    
    // Initialize on mount
    initializeLocation()
    
    // Current location computed
    const currentLocation = computed(() => {
        if (!Array.isArray(locations.value) || locations.value.length === 0) {
            return null
        }
        return locations.value.find(loc => loc.id === selectedLocationId.value) || null
    })
    
    // Location options for select components
    const locationOptions = computed(() => {
        if (!Array.isArray(locations.value)) {
            return []
        }
        return locations.value.map(loc => ({
            label: loc.name,
            value: loc.id,
            type: loc.type,
            address: loc.address
        }))
    })
    
    // Handle location change
    const handleLocationChange = async (locationId) => {
        if (locationId === selectedLocationId.value) return
        
        try {
            // Call the set-default API
            await axios.post(`/inventory/locations/${locationId}/set-default`)
            
            // Update local state
            selectedLocationId.value = locationId
            
            // Store in localStorage for persistence
            localStorage.setItem('preferred_location_id', locationId)
            
            // Simple page reload (not full page)
            router.reload({
                only: ['stats', 'report', 'items', 'locations', 'summary'],
                preserveScroll: true,
                data: { location_id: locationId }
            })
            
            // Show success notification
            notification.success({
                message: 'Location Updated',
                description: 'Default location has been updated successfully.',
                duration: 3
            })
            
        } catch (error) {
            console.error('Failed to set default location:', error)
            
            // Show error notification
            notification.error({
                message: 'Location Update Failed',
                description: 'Failed to update default location. Please try again.',
                duration: 5
            })
        }
    }
    
    // Switch to specific location
    const switchToLocation = (location) => {
        handleLocationChange(location.id)
    }
    
    // Get location by ID
    const getLocationById = (id) => {
        if (!Array.isArray(locations.value)) {
            return null
        }
        return locations.value.find(loc => loc.id === id)
    }
    
    // Get location icon/color based on type
    const getLocationIcon = (type) => {
        const icons = {
            store: 'bg-green-500',
            warehouse: 'bg-blue-500',
            supplier: 'bg-orange-500',
            customer: 'bg-purple-500',
            default: 'bg-gray-500'
        }
        return icons[type?.toLowerCase()] || icons.default
    }
    
    // Watch for page changes to update location
    watch(() => page.props.currentLocation, (newLocation) => {
        if (newLocation && newLocation.id !== selectedLocationId.value) {
            selectedLocationId.value = newLocation.id
        }
    })
    
    return {
        selectedLocationId,
        locations,
        currentLocation,
        locationOptions,
        handleLocationChange,
        switchToLocation,
        getLocationById,
        getLocationIcon
    }
}
