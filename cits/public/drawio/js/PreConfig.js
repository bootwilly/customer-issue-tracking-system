/**
 * Copyright (c) 2006-2024, JGraph Holdings Ltd
 * Copyright (c) 2006-2024, draw.io AG
 */
// Overrides of global vars need to be pre-loaded
window.DRAWIO_PUBLIC_BUILD = true;
window.EXPORT_URL = null; // Replace with the URL of your export server to enable server-side PDF and image export, e.g. https://www.example.com/export. With null, export to PDF uses the print dialog
window.DRAWIO_BASE_URL = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, ''); // CITS: self-hosted base (was null) // Replace with path to base of deployment, e.g. https://www.example.com/folder
window.DRAWIO_VIEWER_URL = window.DRAWIO_BASE_URL + '/js/viewer.min.js'; // CITS (was null) // Replace your path to the viewer js, e.g. https://www.example.com/js/viewer.min.js
window.DRAWIO_LIGHTBOX_URL = window.DRAWIO_BASE_URL; // CITS (was null) // Replace with your lightbox URL, eg. https://www.example.com
window.DRAW_MATH_URL = 'math4/es5';
window.DRAWIO_CONFIG = null; // Replace with your custom draw.io configurations. For more details, https://www.drawio.com/doc/faq/configure-diagram-editor
urlParams['sync'] = 'manual';
// CITS: never talk to diagrams.net services
urlParams['stealth'] = '1';
urlParams['lockdown'] = '1';
// CITS: no plugins and no PWA / service worker; drop URL parameters that load external code or storage
// back-ends (p = plugin list, mode = Google Drive / OneDrive ..., gapi-stealth = Google API loader).
urlParams['plugins'] = '0';
urlParams['pwa'] = '0';
delete urlParams['p'];
delete urlParams['mode'];
delete urlParams['gapi-stealth'];
