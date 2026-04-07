document.addEventListener('DOMContentLoaded', () => {
    // Inject toast container if it doesn't exist
    if (!document.getElementById('toast-container')) {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
});

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let iconPath = '/assets/icons/notification.png'; // default info icon
    if (type === 'success') iconPath = '/assets/icons/success.png';
    if (type === 'error') iconPath = '/assets/icons/warning.png';

    toast.innerHTML = `
        <img src="${iconPath}" class="toast-icon" onerror="this.style.display='none'">
        <span>${message}</span>
    `;

    container.appendChild(toast);

    // Trigger animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);

    // Automatically remove
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400); // Wait for transition
    }, 4000); // 4 seconds visible
}
