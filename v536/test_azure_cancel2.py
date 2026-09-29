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
    print(f"evt={evt}", flush=True)
    print(f"reason={getattr(evt, 'reason', None)}", flush=True)
    print(f"error_code={getattr(evt, 'error_code', None)}", flush=True)
    print(f"error_details={getattr(evt, 'error_details', None)}", flush=True)

recognizer.canceled.connect(on_canceled)

# Start and immediately stop without audio to trigger a possible error?
# Let's trigger authentication failure by using bad key.
speech_config = speechsdk.SpeechConfig(subscription="bad", region=region)
recognizer = speechsdk.SpeechRecognizer(speech_config=speech_config, audio_config=audio_config)
recognizer.canceled.connect(on_canceled)
recognizer.start_continuous_recognition()
time.sleep(2)
recognizer.stop_continuous_recognition()
