class ReaderRecorderProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    this.azureBuffer = new Int16Array(320); // 20 ms at 16 kHz
    this.azureOffset = 0;
  }

  process(inputs) {
    const input = inputs[0];
    if (input && input[0] && input[0].length) {
      const samples = input[0];
      const ratio = sampleRate / 16000;
      const outLength = Math.max(1, Math.round(samples.length / ratio));
      for (let i = 0; i < outLength; i++) {
        const pos = i * ratio;
        const left = Math.min(samples.length - 1, Math.floor(pos));
        const right = Math.min(samples.length - 1, left + 1);
        const frac = pos - left;
        const value = samples[left] * (1 - frac) + samples[right] * frac;
        const clipped = Math.max(-1, Math.min(1, value));
        this.azureBuffer[this.azureOffset++] = clipped < 0 ? clipped * 0x8000 : clipped * 0x7fff;
        if (this.azureOffset === this.azureBuffer.length) {
          const chunk = this.azureBuffer.buffer;
          this.port.postMessage({ pcm16_16k: chunk }, [chunk]);
          this.azureBuffer = new Int16Array(320);
          this.azureOffset = 0;
        }
      }
    }
    return true;
  }
}
registerProcessor("reader-recorder", ReaderRecorderProcessor);
