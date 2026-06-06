<script setup>
defineProps({
    label: String,
    value: String,
    unit: String,
    icon: String,
    trend: { type: Number, default: null },
})
</script>

<template>
    <div class="stat-card">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm text-gray-500">{{ label }}</span>
            <span v-if="icon" class="text-gray-400 text-lg">{{ icon }}</span>
        </div>
        <div class="flex items-baseline gap-1">
            <span class="text-2xl font-semibold text-gray-900">{{ value }}</span>
            <span v-if="unit" class="text-sm text-gray-500">{{ unit }}</span>
        </div>
        <div v-if="trend !== null" class="mt-2 text-xs" :class="trend >= 0 ? 'text-green-600' : 'text-red-500'">
            {{ trend >= 0 ? '+' : '' }}{{ trend }}% vs semaine précédente
        </div>
    </div>
</template>
