import asyncio
from service import AzureSession
import os

async def run():
    ws_queue = asyncio.Queue()
    session = AzureSession(
        loop=asyncio.get_running_loop(),
        ws_queue=ws_queue,
        locale="en-US",
        reference_text="On a quiet Saturday morning. Maya walked to the old garden behind her grandmother's house. The gate was covered with vines, but a narrow path still led to a small wooden bench. Maya Sit there for a moment and listened to the birds singing in the trees. Then she noticed a tiny bluebird hopping beside a fallen petal and carried it toward the nest. Maya smiled. Realized even the familiar place there could always something be new to discovered."
    )
    def capture_event(evt):
        print(f"EVENT: {evt}", flush=True)
    session._queue_emit = capture_event
    await asyncio.to_thread(session.start)

    with open("test.pcm", "rb") as f:
        while True:
            chunk = f.read(3200)
            if not chunk:
                break
            session.audio_queue.put_nowait(chunk)
            await asyncio.sleep(0.1)

    session.audio_queue.put_nowait(None)
    await asyncio.to_thread(session.stop)

if __name__ == "__main__":
os.environ["AZURE_SPEECH_KEY"] = os.environ.get("AZURE_SPEECH_KEY", "")
    os.environ["AZURE_SPEECH_REGION"] = "southeastasia"
    asyncio.run(run())
