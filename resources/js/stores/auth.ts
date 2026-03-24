// stores/auth.ts
import axios from 'axios';
import { defineStore } from 'pinia';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null as null | { id: number; name: string; role: string },
    }),
    actions: {
        async fetchUser() {
            try {
                const { data } = await axios.get('/api/user', {
                    headers: {
                        Authorization: `Bearer ${localStorage.getItem('token')}`,
                    },
                });
                this.user = data; // ← /api/user returns user directly, not data.user
                // console.log('this.user', data);
            } catch {
                this.user = null;
            }
        },
    },
});
