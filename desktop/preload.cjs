// The only things the panel page may ask the main process for.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ningredy', {
    start: (mode) => ipcRenderer.invoke('start', mode),
    stop: () => ipcRenderer.invoke('stop'),
    state: () => ipcRenderer.invoke('state'),
    refresh: () => ipcRenderer.invoke('refresh'),
    open: (target) => ipcRenderer.invoke('open', target),
    tables: () => ipcRenderer.invoke('tables'),
    on: (channel, listener) => {
        if (['log', 'state', 'status'].includes(channel)) {
            ipcRenderer.on(channel, (_event, payload) => listener(payload));
        }
    },
});
