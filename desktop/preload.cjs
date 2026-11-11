// The only things the panel page may ask the main process for.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ningredy', {
    start: (mode) => ipcRenderer.invoke('start', mode),
    stop: () => ipcRenderer.invoke('stop'),
    update: () => ipcRenderer.invoke('update'),
    updateInfo: () => ipcRenderer.invoke('update-info'),
    state: () => ipcRenderer.invoke('state'),
    refresh: () => ipcRenderer.invoke('refresh'),
    open: (target) => ipcRenderer.invoke('open', target),
    on: (channel, listener) => {
        if (['log', 'state', 'status', 'update'].includes(channel)) {
            ipcRenderer.on(channel, (_event, payload) => listener(payload));
        }
    },
});
