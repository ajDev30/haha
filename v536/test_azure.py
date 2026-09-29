import azure.cognitiveservices.speech as speechsdk
import sys
import os
import time

key = os.environ.get("AZURE_SPEECH_KEY")
region = os.environ.get("AZURE_SPEECH_REGION")
speech_config = speechsdk.SpeechConfig(subscription=key, region=region)
stream_format = speechsdk.audio.AudioStreamFormat(samples_per_second=16000, bits_per_sample=16, channels=1)
push_stream = speechsdk.audio.PushAudioInputStream(stream_format=stream_format)
audio_config = speechsdk.audio.AudioConfig(stream=push_stream)
recognizer = speechsdk.SpeechRecognizer(speech_config=speech_config, audio_config=audio_config)

started = False
def on_session_started(evt):
    global started
    started = True
    print("SESSION STARTED FIRED", flush=True)

recognizer.session_started.connect(on_session_started)

recognizer.start_continuous_recognition_async().get()
print("start_continuous_recognition_async returned", flush=True)

time.sleep(5)
print(f"Started after 5s without audio? {started}")

push_stream.write(b'\0' * 32000)
time.sleep(2)
print(f"Started after sending audio? {started}")
