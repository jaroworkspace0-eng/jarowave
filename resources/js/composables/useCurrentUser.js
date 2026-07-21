import axios from 'axios';
import { ref } from 'vue';

const currentUser = ref(null);
const loaded = ref(false);

export function useCurrentUser() {
    async function loadCurrentUser() {
        if (loaded.value) return currentUser.value;
        try {
            const { data } = await axios.get('/api/user');
            currentUser.value = data;
        } catch (e) {
            console.error('Failed to load current user:', e);
            currentUser.value = null;
        } finally {
            loaded.value = true;
        }
        return currentUser.value;
    }

    return { currentUser, loadCurrentUser };
}
