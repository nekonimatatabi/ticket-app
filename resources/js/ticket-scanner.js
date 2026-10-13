import { Html5Qrcode } from 'html5-qrcode';

/**
 * 受付画面で、カメラ越しにチケットの QR コード（チケットのコード）を読み取る。
 *
 * 読み取ったコードは onCode に渡すだけで、受付の判定はサーバー側（CheckInService）が行う。onCode は Promise を返す。
 * - 結果が出るまで、カメラを一時停止する。続けて読んで、前の結果（二重入場の警告など）が上書きされないようにするため。
 *   結果が出たあとも holdMs だけ停止を続けて、係の人が結果を見られるようにする。その間に映ったコードは読み捨てる。
 * - 同じコードは、最後に送ってから ignoreSameCodeMs のあいだ無視する（同じ QR を映し続けても、二重に送らない）。
 * - onCode が失敗したら onError を呼ぶ（通信の失敗など）。
 * ライブラリを差し替えたくなったときのために、読み取りの部分はこのファイルに閉じ込めている。
 */
class TicketScanner {
    constructor(elementId, onCode, { ignoreSameCodeMs = 5000, holdMs = 1500, onError = null } = {}) {
        this.elementId = elementId;
        this.onCode = onCode;
        this.ignoreSameCodeMs = ignoreSameCodeMs;
        this.holdMs = holdMs;
        this.onError = onError;
        this.scanner = null;
        this.busy = false;
        this.recentCodes = new Map();
    }

    async start() {
        this.scanner = new Html5Qrcode(this.elementId);

        await this.scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 240, height: 240 } },
            (text) => this.handle(text),
            () => {},
        );
    }

    async stop() {
        if (this.scanner === null) {
            return;
        }

        try {
            await this.scanner.stop();
            this.scanner.clear();
        } catch (error) {
            // すでに止まっているときのエラーは無視する
        }

        this.scanner = null;
    }

    async handle(text) {
        if (this.busy) {
            return;
        }

        const now = Date.now();
        const lastSentAt = this.recentCodes.get(text);

        if (lastSentAt !== undefined && now - lastSentAt < this.ignoreSameCodeMs) {
            return;
        }

        for (const [code, sentAt] of this.recentCodes) {
            if (now - sentAt >= this.ignoreSameCodeMs) {
                this.recentCodes.delete(code);
            }
        }

        this.recentCodes.set(text, now);
        this.busy = true;
        this.pause();

        try {
            await this.onCode(text);
        } catch (error) {
            // 送れなかったコードは、もう一度読めるように、覚えているのをやめる
            this.recentCodes.delete(text);

            if (this.onError !== null) {
                this.onError(error);
            }
        } finally {
            await new Promise((resolve) => setTimeout(resolve, this.holdMs));
            this.busy = false;
            this.resume();
        }
    }

    pause() {
        try {
            this.scanner?.pause(true);
        } catch (error) {
            // 止められなくても、busy の間はコードを読み捨てるので、先へ進める
        }
    }

    resume() {
        try {
            this.scanner?.resume();
        } catch (error) {
            // すでに止められているときのエラーは無視する
        }
    }
}

window.TicketScanner = TicketScanner;
