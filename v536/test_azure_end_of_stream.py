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

def on_canceled(evt):
    print(f"CANCELED: reason={evt.reason} code={evt.error_code} details={evt.error_details}", flush=True)

recognizer.canceled.connect(on_canceled)
recognizer.start_continuous_recognition()
push_stream.write(b'\0' * 32000)
push_stream.close()
time.sleep(2)
recognizer.stop_continuous_recognition()
