import { shallowRef } from 'vue';
import request from '@/request/http';
import { sessionService } from '@/shared/session/application/SessionService';

const enabled = import.meta.env.VITE_REMIT_PUSH_ENABLED === 'true';
const options = { handleUnauthorized: false };
export const pendingNotice = shallowRef<string>();
let listening = false;
let syncing = false;

function objectPayload(value: unknown): Record<string, unknown> | undefined {
  if (typeof value === 'string' && value.length <= 1024) {
    try { value = JSON.parse(value); } catch { return; }
  }
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    return value as Record<string, unknown>;
  }
}

export function listenForMemberPush() {
  // #ifdef APP-PLUS
  if (!enabled || listening || typeof uni.onPushMessage !== 'function') return;
  listening = true;
  uni.onPushMessage(({ type, data }) => {
    if (type !== 'click') return;
    const message = objectPayload(data);
    const payload = objectPayload(message?.payload) ?? message;
    if (payload?.kind === 'member_notice' && typeof payload.notice_id === 'string' && /^[1-9][0-9]{0,17}$/.test(payload.notice_id)) {
      pendingNotice.value = payload.notice_id;
    }
  });
  // #endif
}

export function syncMemberPush() {
  // #ifdef APP-PLUS
  const token = sessionService.getToken();
  if (!enabled || !token || syncing || typeof uni.getPushClientId !== 'function') return;
  const permission = uni.getAppAuthorizeSetting?.()?.notificationAuthorized;
  if (permission === 'denied') {
    void request.delete('app/notifications/device', options).catch(() => {});
    return;
  }
  syncing = true;
  uni.getPushClientId({
    success: ({ cid }) => {
      if (sessionService.getToken() !== token) return;
      if (!cid || uni.getAppAuthorizeSetting?.()?.notificationAuthorized === 'denied') return;
      void request.put('app/notifications/device', { client_id: cid }, options).catch(() => {});
    },
    complete: () => {
      syncing = false;
      if (sessionService.getToken() !== token) syncMemberPush();
    },
  });
  // #endif
}
