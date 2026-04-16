import './bootstrap';
import { getAppStatus, isProduction } from './lib/app-status';

window.getAppStatus = getAppStatus;   // optional: use in inline scripts
window.isProduction = isProduction;
