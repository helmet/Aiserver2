#define WIN32_LEAN_AND_MEAN

#include <iostream>
#include <Windows.h>
#include <WinSock2.h>
#include <WS2tcpip.h>

#define MAXBUFLEN 4096
#define CONNECT_RETRY 5

/*
	Function definitions
*/
class WSMT 
{
public:
	static int InitConns(char* host, char* port);
	static int ConnFailed();
	static void SendData(SOCKET ConnSock, char* data);
};