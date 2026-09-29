import azure.cognitiveservices.speech as speechsdk
import sys
import os

key = os.environ.get("AZURE_SPEECH_KEY")
region = os.environ.get("AZURE_SPEECH_REGION")
speech_config = speechsdk.SpeechConfig(subscription=key, region=region)
stream_format = speechsdk.audio.AudioStreamFormat(samples_per_second=16000, bits_per_sample=16, channels=1)
push_stream = speechsdk.audio.PushAudioInputStream(stream_format=stream_format)
audio_config = speechsdk.audio.AudioConfig(stream=push_stream)
recognizer = speechsdk.SpeechRecognizer(speech_config=speech_config, audio_config=audio_config)

def on_session_started(_sender, evt):
    print("SESSION STARTED FIRED", flush=True)

recognizer.session_started.connect(on_session_started)
print("calling start_continuous_recognition()", flush=True)
recognizer.start_continuous_recognition()
print("returned", flush=True)
