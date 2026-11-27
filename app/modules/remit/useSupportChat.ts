import { computed, nextTick, ref, watch } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { BASE_URL } from '@/config/api';
import { sessionService } from '@/shared/session/application/SessionService';

export type SupportMessage = {
  id: number;
  sender: 'staff' | 'member';
  body: string;
  created_at?: string;
};
type History = { list: SupportMessage[]; next_after_id: number | null; has_more: boolean };
type Draft = { id: string; body: string };
type ReadStatus = { unread_count: number };

const path = 'app/support/messages';
const socketUrl = import.meta.env.VITE_REVERB_URL;
const socketKey = import.meta.env.VITE_REVERB_APP_KEY;

export function makeMessageId(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const value = Math.floor(Math.random() * 16);
    return (character === 'x' ? value : (value & 3) | 8).toString(16);
  });
}

export function useSupportChat(
  active: () => boolean,
  options: { listen?: () => boolean; expired?: () => void; notificationsChanged?: () => void; couponsChanged?: () => void } = {},
) {
  const connected = computed(() => Boolean(sessionService.getToken()));
  const messages = ref<SupportMessage[]>([]);
  const live = ref(false);
  const status = ref('');
  const sending = ref(false);
  const unread = ref(0);
  const requestOptions = { handleUnauthorized: options.expired === undefined };
  let unreadRequest = 0;
  let pending: Draft | undefined;
  let sync = { cursor: null as number | null, read: 0, running: false, queued: false };

  async function refreshUnread(token = sessionService.getToken()) {
    if (!token) return;
    const id = ++unreadRequest;
    try {
      const result = await request.get<ReadStatus>('app/support/status', {}, requestOptions);
      if (id === unreadRequest && sessionService.getToken() === token && !active()) {
        unread.value = result.unread_count;
      }
    } catch (error) {
      if (sessionService.getToken() === token && error instanceof UnauthorizedError) options.expired?.();
    }
  }

  async function refresh(token = sessionService.getToken()) {
    if (!token || !active()) return;
    const state = sync;
    state.queued = true;
    if (state.running) return;
    state.running = true;

    try {
      while (state.queued && state === sync && sessionService.getToken() === token && active()) {
        state.queued = false;
        const history = await request.get<History>(path, state.cursor === null ? {} : { after_id: state.cursor }, requestOptions);
        if (state !== sync || sessionService.getToken() !== token || !active()) return;
        messages.value.push(...history.list);
        state.cursor = history.next_after_id ?? 0;
        state.queued ||= history.has_more;
        status.value = '';
      }
      await nextTick();
      if (state === sync && sessionService.getToken() === token && active() && state.cursor && state.cursor > state.read) {
        unreadRequest++;
        const id = state.cursor;
        const result = await request.post<ReadStatus>('app/support/read', { message_id: id }, requestOptions);
        if (state === sync && sessionService.getToken() === token) {
          state.read = id;
          unread.value = result.unread_count;
        }
      }
    } catch (error) {
      if (sessionService.getToken() === token && active()) {
        if (error instanceof UnauthorizedError) options.expired?.();
        status.value = error instanceof Error ? error.message : '读取客服消息失败';
      }
    } finally {
      state.running = false;
      if (state === sync && state.queued && active() && sessionService.getToken() === token) void refresh(token);
    }
  }

  async function send(body: string) {
    const token = sessionService.getToken();
    if (!token || sending.value) return false;
    if (!pending || pending.body !== body) pending = { id: makeMessageId(), body };
    const draft = pending;
    sending.value = true;
    try {
      await request.post(path, { client_id: draft.id, body }, requestOptions);
      if (pending === draft) pending = undefined;
      await refresh(token);
      return true;
    } catch (error) {
      if (sessionService.getToken() === token && error instanceof UnauthorizedError) options.expired?.();
      uni.showToast({ title: error instanceof Error ? error.message : '发送失败，请重试', icon: 'none' });
      return false;
    } finally {
      sending.value = false;
    }
  }

  function refreshView(token = sessionService.getToken()) {
    return active() ? refresh(token) : refreshUnread(token);
  }

  watch(active, () => { void refreshView(); });

  watch([options.listen ?? active, () => sessionService.getToken()], ([listening, token], previous, onCleanup) => {
    if (previous?.[1] !== token) {
      sync = { cursor: null, read: 0, running: false, queued: false };
      messages.value = [];
      pending = undefined;
      unread.value = 0;
      status.value = '';
    }
    if (!listening || !token) return;

    void refreshView(token);
    const memberId = sessionService.getCurrentUser()?.memberId;
    if (!socketUrl || !socketKey || !memberId) return;
    const channel = `private-users.${memberId}`;
    const connectionUrl = `${socketUrl.replace(/\/$/, '')}/app/${encodeURIComponent(socketKey)}?protocol=7&client=uni-app&version=1.0`;
    let task: UniApp.SocketTask | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let closed = false;
    let delay = 1000;

    function connect() {
      if (closed) return;
      const current = uni.connectSocket({
        url: connectionUrl,
        complete() {},
      });
      task = current;
      const reconnect = () => {
        if (closed || task !== current || timer !== undefined) return;
        live.value = false;
        task = undefined;
        timer = setTimeout(() => {
          timer = undefined;
          connect();
        }, delay);
        delay = Math.min(delay * 2, 30000);
      };
      current.onClose(reconnect);
      current.onError(() => {
        reconnect();
        current.close({});
      });
      current.onMessage(async ({ data }) => {
        if (closed || task !== current || typeof data !== 'string') return;
        try {
          const frame = JSON.parse(data);
          if (frame.event === 'pusher:connection_established') {
            const { socket_id } = JSON.parse(frame.data);
            const authResponse = await new Promise<{ auth: string }>((resolve, reject) => {
              uni.request({
                url: `${BASE_URL}broadcasting/auth`,
                method: 'POST',
                header: { Authorization: `Bearer ${token}` },
                data: { socket_id, channel_name: channel },
                success: ({ data, statusCode }) => {
                  const response = data as { auth?: string };
                  if (statusCode === 200 && response?.auth) resolve({ auth: response.auth });
                  else reject(new Error('无法订阅客服消息'));
                },
                fail: reject,
              });
            });
            if (!closed && task === current) current.send({ data: JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: authResponse.auth } }) });
          } else if (frame.event === 'pusher_internal:subscription_succeeded') {
            live.value = true;
            delay = 1000;
            void refreshView(token);
            options.notificationsChanged?.();
            options.couponsChanged?.();
          } else if (frame.event === 'pusher:ping') {
            current.send({ data: JSON.stringify({ event: 'pusher:pong', data: {} }) });
          } else if (frame.event === 'support.message') {
            void refreshView(token);
          } else if (frame.event === 'support.read' && !active()) {
            void refreshUnread(token);
          } else if (frame.event === 'notifications.changed') {
            options.notificationsChanged?.();
          } else if (frame.event === 'coupons.changed') {
            options.couponsChanged?.();
          }
        } catch {
          current.close({});
        }
      });
    }

    connect();
    onCleanup(() => {
      closed = true;
      live.value = false;
      clearTimeout(timer);
      task?.close({});
    });
  }, { immediate: true });

  return { connected, messages, live, status, sending, unread, send, refresh, refreshView };
}
