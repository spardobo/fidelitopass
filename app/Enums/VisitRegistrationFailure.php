<?php

namespace App\Enums;

/** Identifies safe dialog feedback and client observations, not domain outcomes. */
enum VisitRegistrationFailure: string
{
    case ManualRequired = 'manual_required';
    case Forbidden = 'forbidden';
    case InvalidCredential = 'invalid_credential';
    case Throttled = 'throttled';
    case ReadUnavailable = 'read_unavailable';
    case Unknown = 'unknown';
    case PromotionChanged = 'promotion_changed';
    case NoPromotion = 'no_promotion';
    case Completed = 'completed';
    case NotAccepted = 'not_accepted';
    case WalletUnavailable = 'wallet_unavailable';
    case DetailsUnavailable = 'details_unavailable';
    case NetworkError = 'network_error';
    case TransportFailed = 'transport_failed';
    case CameraDenied = 'denied';
    case CameraBusy = 'busy';
    case CameraUnavailable = 'unavailable';
    case CameraInitialization = 'initialization';

    // ------------------------------------------------------------------------
    // translation mapping
    // ------------------------------------------------------------------------

    /**
     * Selects the safe translation owned by this feedback code.
     *
     * @return string Translation key, never supplied by the browser.
     */
    public function translationKey(): string
    {
        return match ($this) {
            self::CameraDenied,
            self::CameraBusy,
            self::CameraUnavailable,
            self::CameraInitialization => 'visit-registration.camera_errors.'.$this->value,
            self::TransportFailed => 'visit-registration.read_unavailable',
            default => 'visit-registration.'.$this->value,
        };
    }

    /**
     * Selects an attention notice without repeating the persistent detail.
     *
     * @return string Generic translated notice key.
     */
    public function noticeKey(): string
    {
        return $this === self::DetailsUnavailable
            ? 'visit-registration.details_notice'
            : 'visit-registration.error_notice';
    }

    // ------------------------------------------------------------------------
    // logging metadata
    // ------------------------------------------------------------------------

    /**
     * Selects the severity of a client observation, never a server outcome.
     *
     * @return string PSR severity.
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::TransportFailed,
            self::CameraInitialization => 'error',
            default => 'warning',
        };
    }

    /**
     * Selects events only for observations owned by this presentation boundary.
     *
     * @return string|null Trusted event, or no duplicate domain event.
     */
    public function eventName(): ?string
    {
        return match ($this) {
            self::CameraDenied,
            self::CameraBusy,
            self::CameraUnavailable,
            self::CameraInitialization => 'visit.camera.initialization_failed',
            self::NetworkError,
            self::TransportFailed => 'visit.transport.failed',
            default => null,
        };
    }
}
