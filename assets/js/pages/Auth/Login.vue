<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const submit = () => form.post('/login')
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-semibold text-gray-900">Connexion</h1>
                <p class="text-sm text-gray-500 mt-1">Bienvenue sur Sport Health</p>
            </div>

            <div class="card">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input v-model="form.email" type="email" class="form-input"
                               placeholder="tu@exemple.fr" autocomplete="email" />
                        <p v-if="form.errors.email" class="text-xs text-red-500 mt-1">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
                        <input v-model="form.password" type="password" class="form-input"
                               placeholder="••••••••" autocomplete="current-password" />
                        <p v-if="form.errors.password" class="text-xs text-red-500 mt-1">{{ form.errors.password }}</p>
                    </div>

                    <button @click="submit" :disabled="form.processing" class="btn-primary w-full">
                        {{ form.processing ? 'Connexion...' : 'Se connecter' }}
                    </button>
                </div>

                <p class="text-center text-sm text-gray-500 mt-4">
                    Pas de compte ?
                    <a href="/register" class="text-primary-600 hover:underline">S'inscrire</a>
                </p>
            </div>
        </div>
    </div>
</template>
