import { computed, ref } from 'vue';

const deferredPrompt = ref(null);
const installed = ref(false);
const isIos = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent);
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

/**
 * Só o painel da equipe é instalável: a página traz o manifest apenas fora da loja e do cardápio.
 * O pedido de instalação do navegador chega uma vez por carregamento, por isso é guardado já na abertura.
 */
export function setupInstallApp() {
    if (typeof window === 'undefined' || !document.querySelector('link[rel="manifest"]')) {
        return;
    }

    installed.value = isStandalone();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt.value = event;
    });
    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferredPrompt.value = null;
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
}

export function useInstallApp() {
    /** Chrome, Edge e Android oferecem o botão; no iPhone a instalação é feita pelo menu Compartilhar. */
    const canInstall = computed(() => !installed.value && deferredPrompt.value !== null);
    const needsIosSteps = computed(() => !installed.value && isIos && !isStandalone());

    async function install() {
        const prompt = deferredPrompt.value;

        if (!prompt) {
            return;
        }

        prompt.prompt();
        const { outcome } = await prompt.userChoice;
        deferredPrompt.value = null;
        installed.value = outcome === 'accepted';
    }

    return { canInstall, needsIosSteps, install };
}
