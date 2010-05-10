/*
	Aiserver2 example client application
	---
	This program serves as a demonstration client for the Texas Hold`em tournament
	module created for AIserver2. 
*/

#include "Main.h" // Includes the header files

int WSMT::InitConns(char* host, char* port)
{
	WSAData wsaData;
	int numrcv = 0;
	char buffer[MAXBUFLEN];
	memset(buffer, 0, MAXBUFLEN);
	int iResult;
	iResult = WSAStartup(MAKEWORD(2,2), &wsaData);
	if (iResult != 0)
	{
		// Can't open a Windows Socket closing application
		printf("Fatal error: can not create a Windows Socket.\n");
		return EXIT_FAILURE;
	}
	struct addrinfo *tresult = NULL,
					*tptr = NULL,
					thints;
	ZeroMemory(&thints, sizeof(thints));
	// Set up the socket for connection purposes 
	thints.ai_family = AF_UNSPEC;
	thints.ai_socktype = SOCK_STREAM;
	thints.ai_protocol = IPPROTO_TCP;
	SOCKET ConnSock = INVALID_SOCKET;
	// Try to get information from the specified address
	iResult = getaddrinfo(host, port, &thints, &tresult);
	if (iResult != 0) { return WSMT::ConnFailed();	}
	tptr = tresult;
	ConnSock = socket(tptr->ai_family, tptr->ai_socktype, tptr->ai_protocol);
	if (ConnSock == INVALID_SOCKET)	{ return WSMT::ConnFailed(); }
	iResult = connect(ConnSock, tptr->ai_addr, (int)tptr->ai_addrlen);
	if (iResult == SOCKET_ERROR)
	{
		closesocket(ConnSock);
		ConnSock = INVALID_SOCKET;
	}
	if (ConnSock == INVALID_SOCKET)
	{
		return WSMT::ConnFailed();
	}

	printf("Connected!\n");
	while (true)
	{
		numrcv = recv(ConnSock, buffer, MAXBUFLEN, NULL);
		if (numrcv == SOCKET_ERROR)
		{
			printf("\nThe connection with the server was terminated :-(");
			WSACleanup();
			return EXIT_SUCCESS;
		}
		printf("%s", buffer);
		char* buffer = "";
	}
	return EXIT_SUCCESS;
}

int WSMT::ConnFailed()
{
		printf("\n- Failed to connect to the server specified...");
		WSACleanup();
		return EXIT_FAILURE;
}

void WSMT::SendData(SOCKET ConnSock, char* data)
{
	int iLength = strlen(data);
	send(ConnSock, data, iLength, NULL);
}

int main(int argc, char* argv[])
{
	printf("ConsolePoker - a demo client for AIserver2\n---\n(C) 2010 Patrick Mennen <helmet@helmet.nl>\n\n");
	if (argc == 1)
	{
		printf("\nUsage: %s <server or IPaddress> [port]", argv[0]);
		return EXIT_FAILURE;
	}
	char* server = argv[1];
	char* port = argv[2];
	if (port == NULL) { port = "8000"; }
	printf("Trying to connect to %s:%s... ", server, port);
	int iRetry = 1;
	while (iRetry <= CONNECT_RETRY) 
	{
		if (iRetry > 1) { printf(" Retrying"); }
		int iConnect = WSMT::InitConns(server, port);
		if (iConnect == EXIT_SUCCESS) { 
			WSACleanup();
			return EXIT_SUCCESS;
		}
		iRetry++;
	}
	printf("\n\nFatal Error: given up after %d tries", CONNECT_RETRY);
	return EXIT_FAILURE;
}