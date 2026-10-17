import "./native-validation.js";
import { customerPassAcquisition } from "./customer-pass-acquisition.js";
import { dateTimePicker } from "./date-time-picker.js";
import { passPreviewMotion } from "./pass-preview-motion.js";
import { qrCameraScanner } from "./qr-camera-scanner.js";

document.addEventListener("alpine:init", () => {
    window.Alpine.data("passPreviewMotion", passPreviewMotion);
    window.Alpine.data("customerPassAcquisition", customerPassAcquisition);
    window.Alpine.data("dateTimePicker", dateTimePicker);
    window.Alpine.data("qrCameraScanner", qrCameraScanner);
});
